<?php

declare(strict_types=1);

namespace Linkrot\Files;

use Closure;
use Linkrot\Config;
use Linkrot\Database;
use Linkrot\Duration;
use Linkrot\FileId;
use PDO;

/**
 * Lazy cleanup, there is no cronjob. Database rows go first (one quick transaction), then data files that are
 * unknown to the database. A flock on a lock file keeps concurrent requests from doing the work twice.
 */
final readonly class Cleanup
{
    private const string LAST_RUN = 'last_cleanup';

    /** @param Closure(): int $clock */
    public function __construct(
        private PDO $pdo,
        private Config $config,
        private string $filesDir,
        private string $lockFile,
        private Closure $clock,
    ) {}

    /** Runs only if the interval since the last cleanup has passed. Null if nothing ran. */
    public function runIfDue(): ?CleanupResult
    {
        return $this->run(false);
    }

    /** Runs now, ignoring the interval. Null if another cleanup holds the lock. */
    public function runNow(): ?CleanupResult
    {
        return $this->run(true);
    }

    private function run(bool $force): ?CleanupResult
    {
        if (!$force && !$this->isDue()) {
            return null;
        }
        $lock = @fopen($this->lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }

        try {
            // Somebody may have finished a cleanup between our check and taking the lock.
            if (!$force && !$this->isDue()) {
                return null;
            }
            $result = $this->cleanup();
            $this->setLastRun(($this->clock)());

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function cleanup(): CleanupResult
    {
        $now = ($this->clock)();
        $orphanTimeout = Duration::parse($this->config->orphanRowTimeout);

        $ids = $this->deleteRows($now, $orphanTimeout);
        $filesDeleted = 0;
        foreach ($ids as $id) {
            $filesDeleted += $this->unlink($id);
        }
        $filesDeleted += $this->deleteUnknownFiles($now, $orphanTimeout);

        error_log(sprintf('linkrot cleanup: %d rows, %d files removed', count($ids), $filesDeleted));

        return new CleanupResult(count($ids), $filesDeleted);
    }

    /**
     * @return list<string> ids of the deleted rows
     */
    private function deleteRows(int $now, int $orphanTimeout): array
    {
        // Rows without a data file are found outside of the transaction to keep it short.
        $orphans = [];
        $candidates = $this->pdo->prepare('SELECT id FROM files WHERE created_at < ?');
        $candidates->execute([$now - $orphanTimeout]);
        foreach ($candidates->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (is_string($id) && !is_file($this->filesDir . '/' . $id)) {
                $orphans[] = $id;
            }
        }

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $select = $this->pdo->prepare(
                "SELECT id FROM files WHERE (status = 'ready' AND ((expires_at IS NOT NULL AND expires_at <= :now) OR uses_left = 0))
                    OR (status = 'uploading' AND updated_at < :stale)",
            );
            $select->execute(['now' => $now, 'stale' => $now - Duration::parse($this->config->uploadTimeout)]);
            $ids = array_values(array_unique([...array_map(strval(...), $select->fetchAll(PDO::FETCH_COLUMN)), ...$orphans]));
            $delete = $this->pdo->prepare('DELETE FROM files WHERE id = ?');
            foreach ($ids as $id) {
                $delete->execute([$id]);
            }
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }

        return $ids;
    }

    /** Files that no row refers to. Young files are left alone, an upload may just be starting. */
    private function deleteUnknownFiles(int $now, int $grace): int
    {
        $names = @scandir($this->filesDir);
        if ($names === false) {
            return 0;
        }
        $known = array_flip(array_map(strval(...), Database::query($this->pdo, 'SELECT id FROM files')->fetchAll(PDO::FETCH_COLUMN)));
        $deleted = 0;
        foreach ($names as $name) {
            if (!FileId::isValid($name) || isset($known[$name])) {
                continue;
            }
            $mtime = @filemtime($this->filesDir . '/' . $name);
            if ($mtime !== false && $mtime < $now - $grace) {
                $deleted += $this->unlink($name);
            }
        }

        return $deleted;
    }

    private function unlink(string $id): int
    {
        return @unlink($this->filesDir . '/' . $id) ? 1 : 0;
    }

    private function isDue(): bool
    {
        $last = Database::query($this->pdo, "SELECT value FROM meta WHERE key = '" . self::LAST_RUN . "'")->fetchColumn();

        return !is_string($last) || ($this->clock)() - (int) $last >= Duration::parse($this->config->cleanupInterval);
    }

    private function setLastRun(int $now): void
    {
        $this->pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value')
            ->execute([self::LAST_RUN, (string) $now]);
    }
}

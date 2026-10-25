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
    /** Last run that finished without any error. */
    private const string LAST_OK = 'last_cleanup_ok';
    /** JSON {"at": ..., "message": ...} of the last run that failed or could not remove everything; dropped by the next clean run. */
    private const string LAST_ERROR = 'last_cleanup_error';

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
            try {
                $result = $this->cleanup();
            } catch (\Throwable $e) {
                $this->writeError($e->getMessage());

                throw $e;
            }
            $now = ($this->clock)();
            $this->setMeta(self::LAST_RUN, (string) $now);
            if ($result->filesFailed === 0) {
                $this->setMeta(self::LAST_OK, (string) $now);
                $this->pdo->prepare('DELETE FROM meta WHERE key = ?')->execute([self::LAST_ERROR]);
            } else {
                $this->writeError($result->filesFailed . ' data files could not be removed');
            }

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
        $failed = 0;
        foreach ($ids as $id) {
            $this->tally($this->unlink($id), $filesDeleted, $failed);
        }
        $this->deleteUnknownFiles($now, $orphanTimeout, $filesDeleted, $failed);

        error_log(sprintf('linkrot cleanup: %d rows, %d files removed, %d failed', count($ids), $filesDeleted, $failed));

        return new CleanupResult(count($ids), $filesDeleted, $failed);
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
    private function deleteUnknownFiles(int $now, int $grace, int &$deleted, int &$failed): void
    {
        $names = @scandir($this->filesDir);
        if ($names === false) {
            return;
        }
        $known = array_flip(array_map(strval(...), Database::query($this->pdo, 'SELECT id FROM files')->fetchAll(PDO::FETCH_COLUMN)));
        foreach ($names as $name) {
            if (!FileId::isValid($name) || isset($known[$name])) {
                continue;
            }
            $mtime = @filemtime($this->filesDir . '/' . $name);
            if ($mtime !== false && $mtime < $now - $grace) {
                $this->tally($this->unlink($name), $deleted, $failed);
            }
        }
    }

    /** @return int 1 removed, 0 was not there, -1 exists and could not be removed */
    private function unlink(string $id): int
    {
        $path = $this->filesDir . '/' . $id;
        if (@unlink($path)) {
            return 1;
        }

        return file_exists($path) ? -1 : 0;
    }

    private function tally(int $result, int &$deleted, int &$failed): void
    {
        if ($result < 0) {
            ++$failed;
        } else {
            $deleted += $result;
        }
    }

    public function status(): CleanupStatus
    {
        $ok = $this->meta(self::LAST_OK);
        $error = json_decode($this->meta(self::LAST_ERROR) ?? '', true);
        $at = is_array($error) && is_int($error['at'] ?? null) ? $error['at'] : null;
        $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : null;

        return new CleanupStatus($ok === null ? null : (int) $ok, $at, $message);
    }

    private function writeError(string $message): void
    {
        $this->setMeta(self::LAST_ERROR, json_encode(['at' => ($this->clock)(), 'message' => $message], JSON_THROW_ON_ERROR));
    }

    private function meta(string $key): ?string
    {
        $select = $this->pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $select->execute([$key]);
        $value = $select->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function isDue(): bool
    {
        $last = $this->meta(self::LAST_RUN);

        return $last === null || ($this->clock)() - (int) $last >= Duration::parse($this->config->cleanupInterval);
    }

    private function setMeta(string $key, string $value): void
    {
        $this->pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value')
            ->execute([$key, $value]);
    }
}

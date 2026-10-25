<?php

declare(strict_types=1);

namespace Linkrot\Api;

use Closure;
use Linkrot\Config;
use Linkrot\Database;
use Linkrot\Duration;
use Linkrot\Files\Cleanup;
use Linkrot\Files\FileStore;
use Linkrot\Http\Response;
use PDO;
use Throwable;

/**
 * GET /api/v1/health (token required): is the server ready, and is anything about to go wrong?
 * Levels: `error` means the server cannot do its job, `warn` that it still can but someone should look.
 */
final readonly class HealthController
{
    private const int GIB = 1_073_741_824;

    /** @param Closure(): int $clock */
    public function __construct(
        private Config $config,
        private PDO $pdo,
        private FileStore $store,
        private Cleanup $cleanup,
        private string $dataDir,
        private Closure $clock,
    ) {}

    public function check(): Response
    {
        $checks = [$this->database(), $this->storage(), $this->disk(), $this->cleanupCheck()];
        $levels = array_column($checks, 'level');
        $status = in_array('error', $levels, true) ? 'error' : (in_array('warn', $levels, true) ? 'warn' : 'ok');

        return Response::json(['status' => $status, 'checks' => $checks]);
    }

    /** @return array{name: string, level: string, message: string} */
    private function database(): array
    {
        try {
            Database::assertCurrent($this->pdo);
            Database::query($this->pdo, 'SELECT count(*) FROM files')->fetchColumn();
        } catch (Throwable $e) {
            return $this->result('database', 'error', $e->getMessage());
        }

        return $this->result('database', 'ok', 'schema version ' . Database::schemaVersion($this->pdo));
    }

    /** @return array{name: string, level: string, message: string} */
    private function storage(): array
    {
        if (!is_dir($this->dataDir) || !is_writable($this->dataDir)) {
            return $this->result('storage', 'error', 'the data directory is missing or not writable');
        }
        $files = $this->store->directory();
        if (is_dir($files) && !is_writable($files)) {
            return $this->result('storage', 'error', 'the files directory is not writable');
        }

        return $this->result('storage', 'ok', 'data and files directory are writable');
    }

    /** @return array{name: string, level: string, message: string} */
    private function disk(): array
    {
        $space = $this->store->space();
        if ($space === null) {
            return $this->result('disk', 'ok', 'free space is not available on this system');
        }
        $threshold = max(intdiv($space['total'], 10), self::GIB);
        $message = $this->bytes($space['free']) . ' free of ' . $this->bytes($space['total']);

        return $space['free'] < $threshold
            ? $this->result('disk', 'warn', 'low disk space: ' . $message)
            : $this->result('disk', 'ok', $message);
    }

    /** @return array{name: string, level: string, message: string} */
    private function cleanupCheck(): array
    {
        $status = $this->cleanup->status();
        $now = ($this->clock)();
        $interval = Duration::parse($this->config->cleanupInterval);

        if ($status->lastErrorAt !== null && ($status->lastOkAt === null || $status->lastErrorAt >= $status->lastOkAt)) {
            return $this->result('cleanup', 'warn', 'the last cleanup had errors: ' . ($status->lastError ?? 'unknown'));
        }
        if ($status->lastOkAt === null) {
            return $this->result('cleanup', 'warn', 'the cleanup has not run yet');
        }
        if ($now - $status->lastOkAt > 2 * $interval) {
            return $this->result('cleanup', 'warn', 'the last successful cleanup was ' . $this->ago($now - $status->lastOkAt) . ' ago, it should run every ' . $this->config->cleanupInterval);
        }

        return $this->result('cleanup', 'ok', 'last successful cleanup ' . $this->ago($now - $status->lastOkAt) . ' ago');
    }

    /** @return array{name: string, level: string, message: string} */
    private function result(string $name, string $level, string $message): array
    {
        return ['name' => $name, 'level' => $level, 'message' => $message];
    }

    private function bytes(int $bytes): string
    {
        $value = (float) $bytes;
        $unit = 'B';
        foreach (['KiB', 'MiB', 'GiB', 'TiB'] as $next) {
            if ($value < 1024) {
                break;
            }
            $value /= 1024;
            $unit = $next;
        }

        return sprintf($unit === 'B' ? '%.0f %s' : '%.1f %s', $value, $unit);
    }

    private function ago(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => $seconds . 's',
            $seconds < 7200 => intdiv($seconds, 60) . 'm',
            $seconds < 172_800 => intdiv($seconds, 3600) . 'h',
            default => intdiv($seconds, 86_400) . 'd',
        };
    }
}

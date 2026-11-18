<?php

declare(strict_types=1);

namespace Linkrot\Auth;

use Closure;
use PDO;
use Throwable;

/**
 * The failed-auth limiter for servers without APCu (shared hosting): the same fixed windows in a SQLite database of
 * its own (ratelimit.sqlite next to linkrot.sqlite), so that somebody hammering the server with wrong tokens never
 * holds a lock of the application's database. It fails open: if the database is busy or broken, the attempt is let
 * through and logged. Tokens and mailed codes do not depend on it (256 bit tokens, codes with a few guesses each).
 */
final class SqliteRateLimiter implements RateLimiter
{
    public const string FILE_NAME = 'ratelimit.sqlite';

    /** A limiter that waits for a lock would slow down every request of an attack; a short wait is enough. */
    private const int BUSY_TIMEOUT_MS = 250;

    private ?PDO $pdo = null;
    private readonly Closure $clock;

    /**
     * @param string $namespace the tenant (and :auth for the codes): counters of their own
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly string $path,
        private readonly int $attempts,
        private readonly int $window,
        private readonly string $namespace = '',
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function blockedFor(string $clientIp): ?int
    {
        $now = ($this->clock)();
        try {
            $statement = $this->db()->prepare('SELECT count, until FROM windows WHERE key = ? AND until > ?');
            $statement->execute([$this->key($clientIp), $now]);
            /** @var array{count: int, until: int}|false $row */
            $row = $statement->fetch();
        } catch (Throwable $e) {
            error_log('linkrot: rate limiter unavailable, the attempt is not limited: ' . $e->getMessage());

            return null;
        }
        if ($row === false || $row['count'] < $this->attempts) {
            return null;
        }

        return max(1, $row['until'] - $now);
    }

    public function recordFailure(string $clientIp): void
    {
        $now = ($this->clock)();
        try {
            $pdo = $this->db();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                // windows that are over go first: the table only ever holds the clients of the last window
                $pdo->prepare('DELETE FROM windows WHERE until <= ?')->execute([$now]);
                // the window starts with the first failure
                $pdo->prepare('INSERT INTO windows (key, count, until) VALUES (?, 1, ?) ON CONFLICT (key) DO UPDATE SET count = count + 1')
                    ->execute([$this->key($clientIp), $now + $this->window]);
                $pdo->exec('COMMIT');
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
        } catch (Throwable $e) {
            error_log('linkrot: rate limiter unavailable, the failure is not counted: ' . $e->getMessage());
        }
    }

    private function key(string $clientIp): string
    {
        return $this->namespace . ':' . ClientKey::for($clientIp);
    }

    private function db(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }
        $pdo = new PDO('sqlite:' . $this->path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        // counters, not data: losing the last ones in a crash is fine, an fsync per wrong token is not
        $pdo->exec('PRAGMA synchronous = OFF');
        $pdo->exec('CREATE TABLE IF NOT EXISTS windows (key TEXT PRIMARY KEY, count INTEGER NOT NULL, until INTEGER NOT NULL) STRICT');

        return $this->pdo = $pdo;
    }
}

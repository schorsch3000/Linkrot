<?php

declare(strict_types=1);

namespace Linkrot\Auth;

use Closure;
use PDO;

/**
 * Management sessions: what a mailed code buys. A session may manage the tokens of the host and nothing else (its prefix
 * differs from an API token, so the file routes do not know it), and it ends after a fixed time, however busy it is.
 * Only sha256(token) is stored.
 */
final readonly class SessionStore implements BearerVerifier
{
    public const string PREFIX = 'lm_';
    public const int LIFETIME = 600;

    /** @param (Closure(): int)|null $clock */
    public function __construct(private PDO $pdo, private ?Closure $clock = null) {}

    /** Creates a session and returns its token, which is not stored. */
    public function create(?int $now = null): string
    {
        $now ??= $this->now();
        $this->pdo->prepare('DELETE FROM sessions WHERE expires_at <= ?')->execute([$now]);
        $token = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->pdo->prepare('INSERT INTO sessions (hash, created_at, expires_at) VALUES (?, ?, ?)')
            ->execute([TokenStore::hash($token), $now, $now + self::LIFETIME]);

        return $token;
    }

    public function authenticate(string $token, ?int $now = null): ?string
    {
        if (!str_starts_with($token, self::PREFIX) || strlen($token) > 128) {
            return null;
        }
        $select = $this->pdo->prepare('SELECT 1 FROM sessions WHERE hash = ? AND expires_at > ?');
        $select->execute([TokenStore::hash($token), $now ?? $this->now()]);

        return $select->fetchColumn() === false ? null : 'session';
    }

    private function now(): int
    {
        return $this->clock instanceof \Closure ? ($this->clock)() : time();
    }
}

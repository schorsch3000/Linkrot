<?php

declare(strict_types=1);

namespace Linkrot\Auth;

use PDO;

/**
 * Codes that were mailed to the owner of a host. Only a hash is stored, a code lives ten minutes and gets a few guesses,
 * and it only works together with the id of its challenge. Issuing is limited per host: since a host has one address,
 * that is a limit per address, and it is how nobody can use the server to flood somebody's inbox.
 */
final readonly class ChallengeStore
{
    public const int LIFETIME = 600;
    public const int MAX_ATTEMPTS = 5;
    public const int MIN_SPACING = 30;
    public const int MAX_PER_HOUR = 6;
    public const int CODE_LENGTH = 8;

    /** No 0/O, 1/I/L, so a code can be read from a mail and typed. */
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private PDO $pdo) {}

    /** An id that looks like the real ones: also what unknown addresses get, so the answer does not tell. */
    public static function newId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /** Upper case without the spaces and dashes a code is often shown or copied with. */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('~[\s-]+~', '', $code) ?? $code);
    }

    /** Shows the code in two groups of four. */
    public static function display(string $code): string
    {
        return substr($code, 0, 4) . '-' . substr($code, 4);
    }

    /** False if another mail now would be too soon or too many; the caller then sends nothing and says nothing. */
    public function mayIssue(?int $now = null): bool
    {
        $now ??= time();
        $row = $this->pdo->prepare('SELECT COUNT(*) AS n, MAX(created_at) AS last FROM challenges WHERE created_at > ?');
        $row->execute([$now - 3600]);
        $r = $row->fetch();
        if (!is_array($r)) {
            return true;
        }

        return (int) $r['n'] < self::MAX_PER_HOUR && ($r['last'] === null || $now - (int) $r['last'] >= self::MIN_SPACING);
    }

    /** @return array{id: string, code: string} */
    public function issue(?int $now = null): array
    {
        $now ??= time();
        // challenges of the last hour are kept for mayIssue(), the dead ones before that go
        $this->pdo->prepare('DELETE FROM challenges WHERE created_at <= ?')->execute([$now - 3600]);
        $id = self::newId();
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $this->pdo->prepare('INSERT INTO challenges (id, code_hash, created_at, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$id, $this->hash($id, $code), $now, $now + self::LIFETIME]);

        return ['id' => $id, 'code' => $code];
    }

    /**
     * True if the code is right, once: the challenge is gone afterwards. A try is counted before it is looked at, in the
     * same statement that checks the budget, so parallel requests cannot get more than MAX_ATTEMPTS guesses.
     */
    public function redeem(string $id, string $code, ?int $now = null): bool
    {
        $now ??= time();
        $count = $this->pdo->prepare('UPDATE challenges SET attempts = attempts + 1 WHERE id = ? AND attempts < ? AND expires_at > ?');
        $count->execute([$id, self::MAX_ATTEMPTS, $now]);
        if ($count->rowCount() !== 1) {
            return false;
        }
        $select = $this->pdo->prepare('SELECT code_hash FROM challenges WHERE id = ?');
        $select->execute([$id]);
        $hash = $select->fetchColumn();
        if (!is_string($hash) || !hash_equals($hash, $this->hash($id, self::normalizeCode($code)))) {
            return false;
        }

        // two requests with the right code: only the one that deletes the row wins
        $delete = $this->pdo->prepare('DELETE FROM challenges WHERE id = ?');
        $delete->execute([$id]);

        return $delete->rowCount() === 1;
    }

    private function hash(string $id, string $code): string
    {
        return hash('sha256', $id . ':' . $code);
    }
}

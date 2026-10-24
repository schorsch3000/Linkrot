<?php

declare(strict_types=1);

namespace Linkrot\Auth;

use InvalidArgumentException;
use Linkrot\Database;
use PDO;

/**
 * API tokens. All tokens belong to the same single user; they exist so that every device can have its own and a
 * compromised one can be revoked without rotating anything else. Only sha256(token) is stored.
 */
final readonly class TokenStore
{
    public const string PREFIX = 'lr_';
    public const int MAX_NAME_LENGTH = 32;

    /** `last_used_at` is refreshed at most this often to avoid a write per request. */
    public const int TOUCH_INTERVAL = 3600;

    public function __construct(private PDO $pdo) {}

    public static function isValidName(string $name): bool
    {
        return strlen($name) <= self::MAX_NAME_LENGTH && preg_match('~^[a-z-]+$~', $name) === 1;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Creates a token and returns it. This is the only time the raw token is available.
     *
     * @throws InvalidArgumentException on invalid or already used name
     */
    public function create(string $name, ?int $now = null): string
    {
        if (!self::isValidName($name)) {
            throw new InvalidArgumentException('invalid name: use 1-' . self::MAX_NAME_LENGTH . ' characters of a-z and -');
        }
        $token = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $insert = $this->pdo->prepare('INSERT OR IGNORE INTO tokens (name, hash, created_at) VALUES (?, ?, ?)');
        $insert->execute([$name, self::hash($token), $now ?? time()]);
        if ($insert->rowCount() !== 1) {
            throw new InvalidArgumentException("a token named '$name' already exists");
        }

        return $token;
    }

    public function delete(string $name): bool
    {
        $delete = $this->pdo->prepare('DELETE FROM tokens WHERE name = ?');
        $delete->execute([$name]);

        return $delete->rowCount() === 1;
    }

    /** @return list<array{name: string, last_used_at: int|null}> */
    public function list(): array
    {
        $rows = Database::query($this->pdo, 'SELECT name, last_used_at FROM tokens ORDER BY name')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'name' => (string) $row['name'],
                'last_used_at' => $row['last_used_at'] === null ? null : (int) $row['last_used_at'],
            ];
        }

        return $result;
    }

    /** @return string|null the name of the token, null if the token is unknown */
    public function authenticate(string $token, ?int $now = null): ?string
    {
        if (!str_starts_with($token, self::PREFIX) || strlen($token) > 128) {
            return null;
        }
        $now ??= time();
        $select = $this->pdo->prepare('SELECT name, last_used_at FROM tokens WHERE hash = ?');
        $select->execute([self::hash($token)]);
        $row = $select->fetch();
        if (!is_array($row)) {
            return null;
        }
        $name = (string) $row['name'];
        if ($row['last_used_at'] === null || $now - (int) $row['last_used_at'] >= self::TOUCH_INTERVAL) {
            $this->pdo->prepare('UPDATE tokens SET last_used_at = ? WHERE name = ?')->execute([$now, $name]);
        }

        return $name;
    }
}

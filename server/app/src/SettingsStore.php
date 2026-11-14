<?php

declare(strict_types=1);

namespace Linkrot;

use PDO;

/** The settings of a host, in its `meta` table: one row `setting.<name>` with `1` or `0` per setting that was ever changed. */
final readonly class SettingsStore
{
    private const string PREFIX = 'setting.';

    public function __construct(private PDO $pdo) {}

    public function get(): Settings
    {
        $rows = $this->pdo->query("SELECT key, value FROM meta WHERE key LIKE 'setting.%'");
        $values = [];
        foreach ($rows ? $rows->fetchAll() : [] as $row) {
            $values[substr((string) $row['key'], strlen(self::PREFIX))] = $row['value'] === '1';
        }

        return Settings::fromArray($values);
    }

    /**
     * @param array<string, bool> $changes validated with Settings::validate()
     */
    public function change(array $changes): Settings
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $upsert = $this->pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value');
            foreach ($changes as $key => $value) {
                $upsert->execute([self::PREFIX . $key, $value ? '1' : '0']);
            }
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }

        return $this->get();
    }
}

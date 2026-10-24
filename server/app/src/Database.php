<?php

declare(strict_types=1);

namespace Linkrot;

use PDO;

/** SQLite connection and schema management. */
final class Database
{
    public const int SCHEMA_VERSION = 1;

    private const array MIGRATIONS = [
        1 => <<<'SQL'
            CREATE TABLE files (
                id            TEXT PRIMARY KEY COLLATE BINARY,
                name          TEXT NOT NULL,
                size          INTEGER NOT NULL,
                sha256        TEXT NOT NULL UNIQUE,
                status        TEXT NOT NULL CHECK (status IN ('uploading', 'ready')),
                upload_offset INTEGER NOT NULL DEFAULT 0,
                created_at    INTEGER NOT NULL,
                updated_at    INTEGER NOT NULL,
                completed_at  INTEGER,
                rm_after      INTEGER NOT NULL,
                expires_at    INTEGER,
                uses_left     INTEGER
            ) STRICT;
            CREATE INDEX files_status_updated ON files (status, updated_at);
            CREATE INDEX files_expires ON files (expires_at);
            CREATE TABLE tokens (
                name         TEXT PRIMARY KEY,
                hash         TEXT NOT NULL UNIQUE,
                created_at   INTEGER NOT NULL,
                last_used_at INTEGER
            ) STRICT;
            CREATE TABLE meta (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            ) STRICT;
            SQL,
    ];

    /** Opens the database (WAL, busy timeout, exceptions) and brings the schema up to date. */
    public static function open(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::migrate($pdo);

        return $pdo;
    }

    /** PDO::query() is typed `PDOStatement|false`; with ERRMODE_EXCEPTION it never returns false. */
    public static function query(PDO $pdo, string $sql): \PDOStatement
    {
        return $pdo->query($sql) ?: throw new \RuntimeException('query failed: ' . $sql);
    }

    public static function migrate(PDO $pdo): void
    {
        $current = (int) self::query($pdo, 'PRAGMA user_version')->fetchColumn();
        foreach (self::MIGRATIONS as $version => $sql) {
            if ($version <= $current) {
                continue;
            }
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $pdo->exec($sql);
                $pdo->exec('PRAGMA user_version = ' . $version);
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
        }
    }
}

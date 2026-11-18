<?php

declare(strict_types=1);

namespace Linkrot;

use PDO;

/** SQLite connection and schema management. */
final class Database
{
    public const int SCHEMA_VERSION = 5;

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
        // The running sha256 of an upload (serialized HashContext), so that the last chunk does not re-read the whole file.
        2 => 'ALTER TABLE files ADD COLUMN hash_state BLOB',
        // Streaming uploads (stdin) do not know size and sha256 until they are sealed: both become nullable (UNIQUE allows
        // any number of NULLs). SQLite cannot drop NOT NULL, so the table is rebuilt.
        3 => <<<'SQL'
            CREATE TABLE files_new (
                id            TEXT PRIMARY KEY COLLATE BINARY,
                name          TEXT NOT NULL,
                size          INTEGER,
                sha256        TEXT UNIQUE,
                status        TEXT NOT NULL CHECK (status IN ('uploading', 'ready')),
                upload_offset INTEGER NOT NULL DEFAULT 0,
                created_at    INTEGER NOT NULL,
                updated_at    INTEGER NOT NULL,
                completed_at  INTEGER,
                rm_after      INTEGER NOT NULL,
                expires_at    INTEGER,
                uses_left     INTEGER,
                hash_state    BLOB
            ) STRICT;
            INSERT INTO files_new (id, name, size, sha256, status, upload_offset, created_at, updated_at, completed_at, rm_after, expires_at, uses_left, hash_state)
                SELECT id, name, size, sha256, status, upload_offset, created_at, updated_at, completed_at, rm_after, expires_at, uses_left, hash_state FROM files;
            DROP TABLE files;
            ALTER TABLE files_new RENAME TO files;
            CREATE INDEX files_status_updated ON files (status, updated_at);
            CREATE INDEX files_expires ON files (expires_at);
            SQL,
        // When the last use was counted: a resumed download is free for a while after it, and the cleanup waits after the last use.
        4 => 'ALTER TABLE files ADD COLUMN last_use_at INTEGER',
        // Self-service tokens: a code that was mailed (only its hash, a few guesses) and the short management session it buys.
        5 => <<<'SQL'
            CREATE TABLE IF NOT EXISTS challenges (
                id         TEXT PRIMARY KEY,
                code_hash  TEXT NOT NULL,
                attempts   INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            ) STRICT;
            CREATE TABLE IF NOT EXISTS sessions (
                hash       TEXT PRIMARY KEY,
                created_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            ) STRICT;
            SQL,
    ];

    /** Opens the connection (busy timeout, exceptions). The schema is neither created nor checked here. */
    public static function open(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    /** For the installation (`app/bin/migrate`), development and tests: opens and brings the schema up to date. */
    public static function openMigrated(string $path, string $journal = Tenants::JOURNAL_WAL): PDO
    {
        $pdo = self::open($path);
        self::migrate($pdo, $journal);

        return $pdo;
    }

    /**
     * For the server: opens and only checks the schema version (one PRAGMA).
     *
     * @throws SchemaOutdatedException
     */
    public static function openCurrent(string $path): PDO
    {
        $pdo = self::open($path);
        self::assertCurrent($pdo);

        return $pdo;
    }

    /**
     * For installations without a shell (the shared hosting archive, `auto_migrate` in linkrot-paths.php): an upgrade
     * is new files over the old ones, so the first request after it migrates. One request does the work under a lock,
     * the others wait for it. A database that does not exist is left alone (a host is created by the setup).
     */
    public static function migrateIfOutdated(string $path, string $journal = Tenants::JOURNAL_WAL): void
    {
        if (!is_file($path) || self::schemaVersion(self::open($path)) === self::SCHEMA_VERSION) {
            return;
        }
        $lock = fopen($path . '.migrate-lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException("cannot create the lock file $path.migrate-lock");
        }
        try {
            flock($lock, LOCK_EX);
            $pdo = self::open($path);
            if (self::schemaVersion($pdo) < self::SCHEMA_VERSION) {
                error_log('linkrot: migrating ' . $path . ' to schema version ' . self::SCHEMA_VERSION);
                self::migrate($pdo, $journal);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function schemaVersion(PDO $pdo): int
    {
        return (int) self::query($pdo, 'PRAGMA user_version')->fetchColumn();
    }

    /** @throws SchemaOutdatedException */
    public static function assertCurrent(PDO $pdo): void
    {
        $version = self::schemaVersion($pdo);
        if ($version !== self::SCHEMA_VERSION) {
            throw new SchemaOutdatedException("database schema is version $version, this release needs " . self::SCHEMA_VERSION . ': run app/install.sh');
        }
    }

    /** PDO::query() is typed `PDOStatement|false`; with ERRMODE_EXCEPTION it never returns false. */
    public static function query(PDO $pdo, string $sql): \PDOStatement
    {
        return $pdo->query($sql) ?: throw new \RuntimeException('query failed: ' . $sql);
    }

    /** @param string $journal Tenants::JOURNAL_WAL or JOURNAL_DELETE (global-config.php sqlite_journal) */
    public static function migrate(PDO $pdo, string $journal = Tenants::JOURNAL_WAL): void
    {
        // a property of the database file, not of the connection; switching it needs the file to itself, as here
        $pdo->exec($journal === Tenants::JOURNAL_DELETE ? 'PRAGMA journal_mode = DELETE' : 'PRAGMA journal_mode = WAL');
        $current = self::schemaVersion($pdo);
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

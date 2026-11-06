<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Database;
use Linkrot\SchemaOutdatedException;
use PDOException;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'linkrot-db-') ?: self::fail('tempnam');
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->file . $suffix);
        }
    }

    public function testOpenCreatesSchemaAndIsIdempotent(): void
    {
        Database::openMigrated($this->file);
        $pdo = Database::openMigrated($this->file);

        self::assertSame(Database::SCHEMA_VERSION, (int) Database::query($pdo, 'PRAGMA user_version')->fetchColumn());
        self::assertSame('wal', Database::query($pdo, 'PRAGMA journal_mode')->fetchColumn());
        $tables = Database::query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['files', 'meta', 'tokens'], $tables);
    }

    public function testOpenAloneNeitherCreatesNorChecksTheSchema(): void
    {
        $pdo = Database::open($this->file);

        self::assertSame(0, Database::schemaVersion($pdo));
        self::assertSame([], Database::query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll());
    }

    public function testOpenCurrentRefusesAnOutdatedOrNewerSchema(): void
    {
        try {
            Database::openCurrent($this->file);
            self::fail('a fresh database must be refused');
        } catch (SchemaOutdatedException $e) {
            self::assertStringContainsString('run app/install.sh', $e->getMessage());
        }

        $pdo = Database::openMigrated($this->file);
        Database::openCurrent($this->file);
        $pdo->exec('PRAGMA user_version = ' . (Database::SCHEMA_VERSION + 1));

        $this->expectException(SchemaOutdatedException::class);
        Database::openCurrent($this->file);
    }

    public function testMigratesAVersion1DatabaseAndKeepsItsRows(): void
    {
        $pdo = Database::openMigrated($this->file);
        $pdo->exec("INSERT INTO files (id, name, size, sha256, status, created_at, updated_at, rm_after) VALUES ('abcdefghabcdefgh', 'a', 1, 'h', 'uploading', 1, 1, 60)");
        $pdo->exec('ALTER TABLE files DROP COLUMN hash_state');
        $pdo->exec('PRAGMA user_version = 1');

        $pdo = Database::openMigrated($this->file);

        self::assertSame(Database::SCHEMA_VERSION, Database::schemaVersion($pdo));
        self::assertNull(Database::query($pdo, "SELECT hash_state FROM files WHERE id = 'abcdefghabcdefgh'")->fetchColumn() ?: null);
    }

    public function testSha256IsUnique(): void
    {
        $pdo = Database::openMigrated($this->file);
        $insert = $pdo->prepare(
            "INSERT INTO files (id, name, size, sha256, status, created_at, updated_at, rm_after) VALUES (?, 'a', 1, 'h', 'uploading', 1, 1, 60)",
        );
        $insert->execute(['abcdefghabcdefgh']);

        $this->expectException(PDOException::class);
        $insert->execute(['ABCDEFGHabcdefgh']);
    }

    public function testIdsAreCaseSensitive(): void
    {
        $pdo = Database::openMigrated($this->file);
        $insert = $pdo->prepare(
            'INSERT INTO files (id, name, size, sha256, status, created_at, updated_at, rm_after) VALUES (?, \'a\', 1, ?, \'uploading\', 1, 1, 60)',
        );
        $insert->execute(['abcdefghabcdefgh', 'h1']);
        $insert->execute(['ABCDEFGHabcdefgh', 'h2']);

        self::assertSame(2, (int) Database::query($pdo, 'SELECT count(*) FROM files')->fetchColumn());
    }

    public function testStreamingUploadsHaveNoSizeAndNoHashYet(): void
    {
        $pdo = Database::openMigrated($this->file);
        $insert = $pdo->prepare(
            "INSERT INTO files (id, name, status, created_at, updated_at, rm_after) VALUES (?, 'a', 'uploading', 1, 1, 60)",
        );
        $insert->execute(['abcdefghabcdefgh']);
        $insert->execute(['ijkmnpqrstuvwxyz']);

        self::assertSame(2, (int) Database::query($pdo, 'SELECT count(*) FROM files WHERE size IS NULL AND sha256 IS NULL')->fetchColumn());
    }

    public function testMigrationTo3KeepsRowsAndIndexes(): void
    {
        $pdo = Database::openMigrated($this->file);
        $pdo->exec('DROP TABLE files');
        $pdo->exec("CREATE TABLE files (id TEXT PRIMARY KEY COLLATE BINARY, name TEXT NOT NULL, size INTEGER NOT NULL, sha256 TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL CHECK (status IN ('uploading', 'ready')), upload_offset INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL, completed_at INTEGER, rm_after INTEGER NOT NULL, expires_at INTEGER, uses_left INTEGER, hash_state BLOB) STRICT");
        $pdo->exec("INSERT INTO files (id, name, size, sha256, status, upload_offset, created_at, updated_at, rm_after, hash_state) VALUES ('abcdefghabcdefgh', 'a', 7, 'h', 'uploading', 3, 1, 2, 60, x'0102')");
        $pdo->exec('PRAGMA user_version = 2');

        $pdo = Database::openMigrated($this->file);

        $row = Database::query($pdo, 'SELECT * FROM files')->fetch();
        self::assertIsArray($row);
        self::assertSame([7, 'h', 3, 2, 60], [$row['size'], $row['sha256'], $row['upload_offset'], $row['updated_at'], $row['rm_after']]);
        self::assertSame("\x01\x02", $row['hash_state']);
        $indexes = Database::query($pdo, "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'files' AND name LIKE 'files_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['files_expires', 'files_status_updated'], $indexes);
    }
}

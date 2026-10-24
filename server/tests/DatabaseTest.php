<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Database;
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
        Database::open($this->file);
        $pdo = Database::open($this->file);

        self::assertSame(Database::SCHEMA_VERSION, (int) Database::query($pdo, 'PRAGMA user_version')->fetchColumn());
        self::assertSame('wal', Database::query($pdo, 'PRAGMA journal_mode')->fetchColumn());
        $tables = Database::query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['files', 'meta', 'tokens'], $tables);
    }

    public function testSha256IsUnique(): void
    {
        $pdo = Database::open($this->file);
        $insert = $pdo->prepare(
            "INSERT INTO files (id, name, size, sha256, status, created_at, updated_at, rm_after) VALUES (?, 'a', 1, 'h', 'uploading', 1, 1, 60)",
        );
        $insert->execute(['abcdefghabcdefgh']);

        $this->expectException(PDOException::class);
        $insert->execute(['ABCDEFGHabcdefgh']);
    }

    public function testIdsAreCaseSensitive(): void
    {
        $pdo = Database::open($this->file);
        $insert = $pdo->prepare(
            'INSERT INTO files (id, name, size, sha256, status, created_at, updated_at, rm_after) VALUES (?, \'a\', 1, ?, \'uploading\', 1, 1, 60)',
        );
        $insert->execute(['abcdefghabcdefgh', 'h1']);
        $insert->execute(['ABCDEFGHabcdefgh', 'h2']);

        self::assertSame(2, (int) Database::query($pdo, 'SELECT count(*) FROM files')->fetchColumn());
    }
}

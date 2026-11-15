<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Tests\Support\AppTestCase;
use PDO;
use PDOStatement;

/** The `files` insert retries a clashing id, and only that: any other database error ends the request at once. */
final class InsertErrorTest extends AppTestCase
{
    private CountingPdo $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new CountingPdo('sqlite:' . $this->dir . '/linkrot.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 50');
        // the app works with this connection from now on
        new \ReflectionProperty($this->app, 'pdo')->setValue($this->app, $this->pdo);
    }

    /** The first use of a token writes `last_used_at`; after that it is left alone for an hour, so the database can fail below it. */
    private function warmUp(): void
    {
        self::assertSame(200, $this->request('GET', '/api/v1/health')->status);
        $this->pdo->inserts = 0;
    }

    /** @return array{int, array<string, mixed>} */
    private function create(): array
    {
        return $this->call('POST', '/api/v1/files', ['name' => 'a.txt', 'size' => 3, 'sha256' => hash('sha256', 'abc')]);
    }

    public function testADatabaseThatCannotBeWrittenIsNotRetriedAsAnIdCollision(): void
    {
        $this->warmUp();
        $this->pdo->exec('PRAGMA query_only = ON');

        [$status] = $this->create();

        self::assertSame(500, $status);
        self::assertSame(1, $this->pdo->inserts, 'one attempt, not five');
    }

    public function testABusyDatabaseIsNotRetriedAsAnIdCollision(): void
    {
        $this->warmUp();
        $other = new PDO('sqlite:' . $this->dir . '/linkrot.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->exec('BEGIN EXCLUSIVE'); // another process is writing
        try {
            $start = microtime(true);

            [$status] = $this->create();

            self::assertSame(500, $status);
            self::assertSame(1, $this->pdo->inserts, 'one attempt, not five');
            self::assertLessThan(2.0, microtime(true) - $start);
        } finally {
            $other->exec('ROLLBACK');
        }
    }

    public function testACreateThatWorksIsOneInsert(): void
    {
        [$status, $body] = $this->create();

        self::assertSame(201, $status);
        self::assertSame('uploading', $body['status']);
        self::assertSame(1, $this->pdo->inserts);
    }

    public function testAClashOfTheSha256IsStillAnswered(): void
    {
        self::assertSame(201, $this->create()[0]);

        [$status, $body] = $this->create();

        self::assertSame(200, $status, 'the incomplete upload is resumed');
        self::assertSame(1, $this->pdo->inserts, 'no second row was tried');
        self::assertIsString($body['id'] ?? null);
    }
}

final class CountingPdo extends PDO
{
    public int $inserts = 0;

    /** @param array<array-key, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'INSERT INTO files')) {
            ++$this->inserts;
        }

        return parent::prepare($query, $options);
    }
}

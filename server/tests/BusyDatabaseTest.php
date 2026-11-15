<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Tests\Support\AppTestCase;
use PDO;
use PDOException;

/** A database that another process holds (SQLITE_BUSY after the busy timeout) is a state to wait out, not a bug: 503. */
final class BusyDatabaseTest extends AppTestCase
{
    private PDO $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->db()->exec('PRAGMA busy_timeout = 50');
        // the first use of a token writes last_used_at; after that it is left alone for an hour, so reads and writes can fail below it
        self::assertSame(200, $this->request('GET', '/api/v1/health')->status);
        $this->other = new PDO('sqlite:' . $this->dir . '/linkrot.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function lock(): void
    {
        $this->other->exec('BEGIN EXCLUSIVE'); // another process is writing
    }

    protected function tearDown(): void
    {
        try {
            $this->other->exec('ROLLBACK');
        } catch (PDOException) {
            // no transaction is open
        }
        parent::tearDown();
    }

    private function assertBusy(Response $response): void
    {
        self::assertSame(503, $response->status, $response->body);
        self::assertSame('busy', json_decode($response->body, true)['error']['code'] ?? null);
        self::assertMatchesRegularExpression('~^[1-9][0-9]?$~', $response->headers['Retry-After'] ?? '', 'Retry-After in seconds');
    }

    public function testAWriteAnswers503WithRetryAfter(): void
    {
        $this->lock();

        $this->assertBusy($this->request('POST', '/api/v1/files', json: ['name' => 'a.txt', 'size' => 3, 'sha256' => hash('sha256', 'abc')]));
    }

    /** The database is in WAL mode: readers are never blocked, only writers wait for each other. */
    public function testReadsKeepWorkingWhileAnotherProcessWrites(): void
    {
        $id = $this->upload('abc');
        $this->lock();

        self::assertSame(200, $this->request('GET', '/api/v1/files')->status);
        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);
        self::assertSame(200, $this->request('GET', "/file/$id/a.txt", auth: false)->status);
    }

    /** A download that counts a use writes, so it can be busy, and the visitor is told to try again. */
    public function testAPublicDownloadThatCountsAUseAnswers503WithRetryAfter(): void
    {
        $id = $this->upload('abc', extra: ['uses' => 2]);
        $this->lock();

        $this->assertBusy($this->request('GET', "/dl/$id/a.txt", auth: false));
    }

    /** Sealing a stream looks for a winner when the database says no; a busy database must still be seen as busy. */
    public function testSealingWhileTheDatabaseIsBusyAnswers503NotAMysteryError(): void
    {
        $created = $this->call('POST', '/api/v1/files', ['name' => 'stdin.txt', 'stream' => true])[1];
        $id = $created['id'];
        self::assertIsString($id);
        $data = 'abc';
        $chunk = $this->request('PATCH', "/api/v1/files/$id/content", [
            'content-type' => 'application/offset+octet-stream',
            'upload-offset' => '0',
        ], raw: $data . hash('sha256', $data, true));
        self::assertSame(200, $chunk->status);
        $this->lock();

        $this->assertBusy($this->request('POST', "/api/v1/files/$id/seal", json: ['size' => 3, 'sha256' => hash('sha256', $data)]));
    }

    public function testSealingWithAReadOnlyDatabaseIsAnInternalError(): void
    {
        $created = $this->call('POST', '/api/v1/files', ['name' => 'stdin.txt', 'stream' => true])[1];
        $id = $created['id'];
        self::assertIsString($id);
        $data = 'abc';
        $this->request('PATCH', "/api/v1/files/$id/content", [
            'content-type' => 'application/offset+octet-stream',
            'upload-offset' => '0',
        ], raw: $data . hash('sha256', $data, true));
        $this->app->db()->exec('PRAGMA query_only = ON');

        self::assertSame(500, $this->request('POST', "/api/v1/files/$id/seal", json: ['size' => 3, 'sha256' => hash('sha256', $data)])->status);
    }

    public function testTheSameRequestWorksOnceTheLockIsGone(): void
    {
        $create = ['name' => 'a.txt', 'size' => 3, 'sha256' => hash('sha256', 'abc')];
        $this->lock();
        $this->assertBusy($this->request('POST', '/api/v1/files', json: $create));
        $this->other->exec('ROLLBACK');

        self::assertSame(201, $this->request('POST', '/api/v1/files', json: $create)->status);
    }

    public function testOtherDatabaseErrorsAreStillAnInternalError(): void
    {
        $this->app->db()->exec('PRAGMA query_only = ON');

        $response = $this->request('POST', '/api/v1/files', json: ['name' => 'a.txt', 'size' => 3, 'sha256' => hash('sha256', 'abc')]);

        self::assertSame(500, $response->status);
        self::assertSame('internal', json_decode($response->body, true)['error']['code'] ?? null);
    }

    /** @return iterable<string, array{int, int}> SQLite result code, expected status */
    public static function resultCodes(): iterable
    {
        yield 'busy' => [5, 503];
        yield 'locked' => [6, 503];
        yield 'busy, recovery (extended code)' => [261, 503];
        yield 'busy, snapshot (extended code)' => [517, 503];
        yield 'read-only' => [8, 500];
        yield 'constraint' => [19, 500];
        yield 'disk full' => [13, 500];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resultCodes')]
    public function testAnErrorIsBusyByItsSqliteResultCodeAlsoWhenWrapped(int $code, int $status): void
    {
        $pdo = new PDOException('database says no');
        $pdo->errorInfo = ['HY000', $code, 'database says no'];
        $this->app->router()->add('GET', '/boom/direct', static fn(): Response => throw $pdo);
        $this->app->router()->add('GET', '/boom/wrapped', static fn(): Response => throw new \RuntimeException('cannot do it', 0, $pdo));

        foreach (['/boom/direct', '/boom/wrapped'] as $path) {
            $response = $this->app->handle(new Request('GET', $path, [], [], ['REMOTE_ADDR' => '192.0.2.1']));

            self::assertSame($status, $response->status, $path);
        }
    }
}

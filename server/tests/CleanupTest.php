<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Database;
use Linkrot\Tests\Support\AppTestCase;

final class CleanupTest extends AppTestCase
{
    /**
     * @phpstan-impure
     *
     * @return array<string, mixed>
     */
    private function cleanup(): array
    {
        [$status, $body] = $this->call('POST', '/api/v1/cleanup');
        self::assertSame(200, $status);

        return $body;
    }

    private function path(string $id): string
    {
        return $this->dir . '/files/' . $id;
    }

    private function rows(): int
    {
        return (int) Database::query($this->app->db(), 'SELECT count(*) FROM files')->fetchColumn();
    }

    public function testRemovesExpiredAndKeepsLive(): void
    {
        $gone = $this->upload('one', rmAfter: '1h');
        $kept = $this->upload('two', rmAfter: '2d');
        $this->now += 3601 + 3600; // expired, and the grace period (orphan_row_timeout, 1h) is over

        self::assertSame(['ran' => true, 'rows_deleted' => 1, 'files_deleted' => 1], $this->cleanup());
        self::assertFileDoesNotExist($this->path($gone));
        self::assertFileExists($this->path($kept));
        self::assertSame(1, $this->rows());
    }

    public function testRemovesExhaustedFilesPhysically(): void
    {
        $id = $this->upload('one', extra: ['uses' => 1]);
        $this->request('GET', "/dl/$id/a.txt", auth: false);
        self::assertFileExists($this->path($id), 'the last use must not unlink before nginx opened the file');

        $this->now += 3601; // the grace period (orphan_row_timeout) after the last use

        $result = $this->cleanup();

        self::assertSame(1, $result['rows_deleted']);
        self::assertFileDoesNotExist($this->path($id));
    }

    public function testKeepsExhaustedFilesDuringTheGracePeriod(): void
    {
        $id = $this->upload('one', extra: ['uses' => 1]);
        $this->request('GET', "/dl/$id/a.txt", auth: false);
        $this->now += 3000;

        // a cleanup that runs while nginx may still be opening the file must leave it alone
        $result = $this->cleanup();

        self::assertSame(0, $result['rows_deleted']);
        self::assertFileExists($this->path($id));
        self::assertSame(1, $this->rows());

        $this->now += 601;
        self::assertSame(1, $this->cleanup()['rows_deleted']);
        self::assertFileDoesNotExist($this->path($id));
    }

    public function testAnExhaustedFileFromBeforeLastUseAtIsJudgedByUpdatedAt(): void
    {
        $id = $this->upload('one', extra: ['uses' => 1]);
        $this->request('GET', "/dl/$id/a.txt", auth: false);
        Database::query($this->app->db(), 'UPDATE files SET last_use_at = NULL'); // as migrated from schema 3
        $this->now += 3601;

        self::assertSame(1, $this->cleanup()['rows_deleted']);
        self::assertFileDoesNotExist($this->path($id));
    }

    public function testRemovesStaleUploadsOnly(): void
    {
        $stale = $this->call('POST', '/api/v1/files', ['name' => 'a', 'size' => 5, 'sha256' => str_repeat('a', 64)])[1]['id'];
        $this->now += 86400 - 10;
        $fresh = $this->call('POST', '/api/v1/files', ['name' => 'b', 'size' => 5, 'sha256' => str_repeat('b', 64)])[1]['id'];
        $this->now += 20;
        self::assertIsString($stale);
        self::assertIsString($fresh);

        $result = $this->cleanup();

        self::assertSame(1, $result['rows_deleted']);
        self::assertFileDoesNotExist($this->path($stale));
        self::assertFileExists($this->path($fresh));
    }

    private function staleUpload(string $sha = 'a'): string
    {
        $id = $this->call('POST', '/api/v1/files', ['name' => 'a', 'size' => 5, 'sha256' => str_repeat($sha, 64)])[1]['id'];
        self::assertIsString($id);
        $this->now += 86400 + 1;

        return $id;
    }

    public function testAStaleUploadThatAChunkIsWritingIsLeftAloneUntilTheNextRun(): void
    {
        $id = $this->staleUpload();
        $writer = fopen($this->path($id), 'r+b') ?: self::fail('open');
        self::assertTrue(flock($writer, LOCK_EX | LOCK_NB), 'the test plays the chunk that holds the lock');

        $result = $this->cleanup();

        self::assertSame(0, $result['rows_deleted']);
        self::assertSame(1, $this->rows());
        self::assertFileExists($this->path($id), 'the file is not unlinked under a running writer');

        flock($writer, LOCK_UN);
        fclose($writer);
        $result = $this->cleanup();

        self::assertSame(1, $result['rows_deleted']);
        self::assertSame(1, $result['files_deleted']);
        self::assertSame(0, $this->rows());
        self::assertFileDoesNotExist($this->path($id));
    }

    public function testOnlyTheLockedUploadIsSkipped(): void
    {
        $locked = $this->staleUpload('a');
        $free = $this->call('POST', '/api/v1/files', ['name' => 'b', 'size' => 5, 'sha256' => str_repeat('b', 64)])[1]['id'];
        self::assertIsString($free);
        Database::query($this->app->db(), 'UPDATE files SET updated_at = updated_at - 100000 WHERE id = ' . $this->app->db()->quote($free));
        $writer = fopen($this->path($locked), 'r+b') ?: self::fail('open');
        flock($writer, LOCK_EX | LOCK_NB);

        $result = $this->cleanup();

        self::assertSame(1, $result['rows_deleted']);
        self::assertFileExists($this->path($locked));
        self::assertFileDoesNotExist($this->path($free));
        flock($writer, LOCK_UN);
        fclose($writer);
    }

    public function testAResumedUploadIsNotStaleAnyMoreAndSurvivesTheCleanup(): void
    {
        $id = $this->staleUpload();
        [$status, $body] = $this->call('POST', '/api/v1/files', ['name' => 'a', 'size' => 5, 'sha256' => str_repeat('a', 64)]);
        self::assertSame(200, $status);
        self::assertSame($id, $body['id']);

        self::assertSame(0, $this->cleanup()['rows_deleted']);
        self::assertFileExists($this->path($id));
    }

    public function testAStaleUploadWithoutADataFileIsStillRemoved(): void
    {
        $id = $this->staleUpload();
        unlink($this->path($id));

        self::assertSame(1, $this->cleanup()['rows_deleted']);
        self::assertSame(0, $this->rows());
    }

    public function testRemovesRowsWithoutDataFileAfterTimeout(): void
    {
        $id = $this->upload('one');
        unlink($this->path($id));

        self::assertSame(0, $this->cleanup()['rows_deleted']);
        $this->now += 3601;
        self::assertSame(1, $this->cleanup()['rows_deleted']);
        self::assertSame(0, $this->rows());
    }

    public function testRemovesUnknownFilesButNotYoungOrForeignOnes(): void
    {
        $this->upload('one');
        $old = 'abcd7KfRtm4pWxnZ';
        $young = 'wxyz7KfRtm4pWxnZ';
        file_put_contents($this->path($old), 'orphan');
        file_put_contents($this->path($young), 'orphan');
        file_put_contents($this->path('notes.txt'), 'not ours');
        touch($this->path($old), $this->now - 7200);
        touch($this->path($young), $this->now - 60);
        touch($this->path('notes.txt'), $this->now - 7200);

        $result = $this->cleanup();

        self::assertSame(['ran' => true, 'rows_deleted' => 0, 'files_deleted' => 1], $result);
        self::assertFileDoesNotExist($this->path($old));
        self::assertFileExists($this->path($young));
        self::assertFileExists($this->path('notes.txt'));
    }

    public function testSkippedWhenLocked(): void
    {
        $this->upload('one');
        $lock = fopen($this->dir . '/cleanup.lock', 'c') ?: self::fail('lock');
        flock($lock, LOCK_EX);

        self::assertSame(['ran' => false], $this->cleanup());

        flock($lock, LOCK_UN);
        self::assertTrue($this->cleanup()['ran']);
    }

    public function testLazyRunHonoursInterval(): void
    {
        $id = $this->upload('one', rmAfter: '1h');
        $this->now += 3601 + 3600; // expired, and past the grace period

        $this->app->cleanupIfDue();
        self::assertFileDoesNotExist($this->path($id), 'first request after start is due');

        $second = $this->upload('two', rmAfter: '1h');
        $this->now += 3601 + 3600;
        $this->app->cleanupIfDue();
        self::assertFileExists($this->path($second), 'within 8h nothing runs');

        $this->now += 8 * 3600;
        $this->app->cleanupIfDue();
        self::assertFileDoesNotExist($this->path($second));
    }

    /** @return array<string, array<string, mixed>> the checks of GET /health by name */
    private function healthChecks(): array
    {
        [$status, $body] = $this->call('GET', '/api/v1/health');
        self::assertSame(200, $status);
        $checks = [];
        foreach ($body['checks'] as $check) {
            $checks[$check['name']] = $check;
        }

        return $checks;
    }

    /** A directory where the lock file should be: it cannot be opened, whoever tries (root included). */
    private function breakTheLock(): void
    {
        mkdir($this->dir . '/cleanup.lock');
    }

    public function testACleanupLockThatCannotBeOpenedIsReportedByTheApi(): void
    {
        $this->breakTheLock();

        $response = $this->request('POST', '/api/v1/cleanup');

        self::assertSame(500, $response->status, 'not "ran: false", that means another cleanup is running');
        self::assertStringContainsString('cleanup lock', $response->body);
    }

    public function testACleanupLockThatCannotBeOpenedShowsInTheHealthCheck(): void
    {
        $this->breakTheLock();
        $this->app->cleanupIfDue();

        $cleanup = $this->healthChecks()['cleanup'];

        self::assertSame('warn', $cleanup['level']);
        self::assertStringContainsString('cleanup lock', $cleanup['message']);
        self::assertStringNotContainsString('not run yet', $cleanup['message']);
    }

    public function testTheLazyCleanupTriesAgainOnlyAfterTheInterval(): void
    {
        $this->breakTheLock();
        $this->app->cleanupIfDue();
        $first = $this->errorAt();
        self::assertSame($this->now, $first);

        $this->now += 60;
        $this->app->cleanupIfDue();
        self::assertSame($first, $this->errorAt(), 'every request must not try (and write to the database) again');

        $this->now += 8 * 3600;
        $this->app->cleanupIfDue();
        self::assertSame($this->now, $this->errorAt());
    }

    public function testOnceTheLockCanBeOpenedTheCleanupRunsAndTheErrorIsGone(): void
    {
        $this->breakTheLock();
        $this->app->cleanupIfDue();
        rmdir($this->dir . '/cleanup.lock');

        self::assertTrue($this->cleanup()['ran']);

        self::assertSame('ok', $this->healthChecks()['cleanup']['level']);
    }

    private function errorAt(): ?int
    {
        $json = Database::query($this->app->db(), "SELECT value FROM meta WHERE key = 'last_cleanup_error'")->fetchColumn();
        $error = is_string($json) ? json_decode($json, true) : null;

        return is_array($error) && is_int($error['at'] ?? null) ? $error['at'] : null;
    }
}

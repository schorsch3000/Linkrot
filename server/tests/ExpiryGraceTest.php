<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Config;
use Linkrot\Database;
use Linkrot\DownloadMode;
use Linkrot\Tests\Support\AppTestCase;

/**
 * A link expires at once, but the file is only deleted after a grace period: PHP answers a download with
 * X-Accel-Redirect and nginx opens the file a moment later, and a request that arrives after the expiry must not unlink
 * the file under the download that was authorized before it.
 */
final class ExpiryGraceTest extends AppTestCase
{
    protected function config(): Config
    {
        return new Config(baseUrl: 'https://lr.example', chunkSize: 10, maxRmAfter: '30d', downloadMode: DownloadMode::Xaccel);
    }

    private function path(string $id): string
    {
        return $this->dir . '/files/' . $id;
    }

    private function rows(): int
    {
        return (int) Database::query($this->app->db(), 'SELECT count(*) FROM files')->fetchColumn();
    }

    public function testADownloadThatWasAuthorizedBeforeTheExpiryKeepsItsFile(): void
    {
        $id = $this->upload('abc', rmAfter: '1h');
        $expires = $this->now + 3600;

        $this->now = $expires - 1;
        $first = $this->request('GET', "/dl/$id/a.txt", auth: false);
        self::assertSame(200, $first->status);
        self::assertArrayHasKey('X-Accel-Redirect', $first->headers, 'PHP has handed the file to nginx, which opens it in a moment');

        $this->now = $expires + 1;
        self::assertSame(404, $this->request('GET', "/dl/$id/a.txt", auth: false)->status, 'the link is dead at once');
        self::assertSame(404, $this->request('GET', "/file/$id/a.txt", auth: false)->status);
        self::assertSame(404, $this->call('GET', "/api/v1/files/$id")[0]);

        self::assertFileExists($this->path($id), 'a request after the expiry must not unlink the file under the first download');
        self::assertSame(1, $this->rows());
    }

    public function testACleanupDuringTheGracePeriodLeavesTheExpiredFileAlone(): void
    {
        $id = $this->upload('abc', rmAfter: '1h');
        $expires = $this->now + 3600;

        $this->now = $expires + 1;
        self::assertSame(0, $this->call('POST', '/api/v1/cleanup')[1]['rows_deleted']);
        self::assertFileExists($this->path($id));

        $this->now = $expires + 3600 - 1; // one second short of orphan_row_timeout (1h)
        self::assertSame(0, $this->call('POST', '/api/v1/cleanup')[1]['rows_deleted']);
        self::assertFileExists($this->path($id));
    }

    public function testTheCleanupRemovesTheExpiredFileOnceTheGracePeriodIsOver(): void
    {
        $id = $this->upload('abc', rmAfter: '1h');
        $this->now += 3600 + 3601;

        $result = $this->call('POST', '/api/v1/cleanup')[1];

        self::assertSame(1, $result['rows_deleted']);
        self::assertFileDoesNotExist($this->path($id));
        self::assertSame(0, $this->rows());
    }

    public function testAnExpiredFileIsGoneForTheApiAndTheQuotaAtOnceThoughItIsStillOnDisk(): void
    {
        $id = $this->upload('abc', rmAfter: '1h');
        $this->now += 3601;

        $list = $this->call('GET', '/api/v1/files')[1];

        self::assertSame([], $list['files']);
        self::assertSame(0, $list['quota']['used'], 'it does not count against the quota');
        self::assertFileExists($this->path($id));
    }

    public function testAFileThatIsNotExpiredIsNeverTouched(): void
    {
        $id = $this->upload('abc', rmAfter: '2d');
        $this->now += 3600 + 3601;

        self::assertSame(0, $this->call('POST', '/api/v1/cleanup')[1]['rows_deleted']);
        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);
    }
}

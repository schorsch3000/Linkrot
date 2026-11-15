<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Config;
use Linkrot\DownloadMode;
use Linkrot\Tests\Support\AppTestCase;

/**
 * The pages cache (rendered HTML and index.json) is only a cache: every public route reads the footer through it, so a
 * cache that cannot be made must never take the downloads and the start page with it.
 */
final class PagesCacheTest extends AppTestCase
{
    protected function config(): Config
    {
        return new Config(baseUrl: 'https://lr.example', chunkSize: 10, maxRmAfter: '30d', downloadMode: DownloadMode::Xaccel);
    }

    /** A published page, a file, and a cache that has to be made again (as after a release with another renderer). */
    private function staleCache(): string
    {
        $put = $this->request('PUT', '/api/v1/pages/imprint', raw: "---\ntitle: Impressum\nlink: Impressum\n---\n# Angaben\n\nMusterstraße 1\n");
        self::assertSame(200, $put->status);
        $id = $this->upload('abc');
        unlink($this->dir . '/pages/index.json');
        unlink($this->dir . '/pages/imprint.html');

        return $id;
    }

    private function assertPublicRoutesWork(string $id): void
    {
        $page = $this->request('GET', "/file/$id/a.txt", auth: false);
        self::assertSame(200, $page->status, 'the download page');
        self::assertStringContainsString('href="/page/imprint"', $page->body, 'the footer is there');
        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", auth: false)->status, 'the download');
        self::assertSame(200, $this->request('GET', '/', auth: false)->status, 'the start page');
        self::assertSame(404, $this->request('GET', "/dl/{$id}x/a.txt", auth: false)->status, 'the not-found page');
        $imprint = $this->request('GET', '/page/imprint', auth: false);
        self::assertSame(200, $imprint->status, 'the page itself');
        self::assertStringContainsString('Musterstraße 1', $imprint->body);
    }

    public function testPublicRoutesWorkWhenTheCacheCannotBeWritten(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root can write anywhere');
        }
        $id = $this->staleCache();
        chmod($this->dir . '/pages', 0o555); // php-fpm may not write here
        try {
            $this->assertPublicRoutesWork($id);
            self::assertFileDoesNotExist($this->dir . '/pages/index.json', 'nothing could be written');
        } finally {
            chmod($this->dir . '/pages', 0o755);
        }
    }

    public function testPublicRoutesWorkWhenThePagesLockCannotBeOpened(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root can write anywhere');
        }
        $id = $this->staleCache();
        file_put_contents($this->dir . '/pages/pages.lock', '');
        chmod($this->dir . '/pages/pages.lock', 0o000);
        chmod($this->dir . '/pages', 0o555);
        try {
            $this->assertPublicRoutesWork($id);
        } finally {
            chmod($this->dir . '/pages', 0o755);
            chmod($this->dir . '/pages/pages.lock', 0o644);
        }
    }

    /** A visitor does not queue behind the process that makes the cache; it renders for itself. */
    public function testPublicRoutesDoNotWaitForAnotherProcessThatIsMakingTheCache(): void
    {
        $id = $this->staleCache();
        $lock = fopen($this->dir . '/pages/pages.lock', 'c') ?: self::fail('open');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'the test plays the other process');
        try {
            $start = microtime(true);
            $this->assertPublicRoutesWork($id);

            self::assertLessThan(5.0, microtime(true) - $start);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testTheCacheIsMadeAgainWhenNothingGetsInTheWay(): void
    {
        $id = $this->staleCache();

        $this->assertPublicRoutesWork($id);

        self::assertFileExists($this->dir . '/pages/index.json');
        self::assertFileExists($this->dir . '/pages/imprint.html');
    }

    public function testWritingAPageStillFailsLoudlyWhenTheDirectoryIsNotWritable(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root can write anywhere');
        }
        $this->staleCache();
        chmod($this->dir . '/pages', 0o555);
        try {
            $response = $this->request('PUT', '/api/v1/pages/other', raw: "---\ntitle: Other\n---\nx\n");

            self::assertSame(500, $response->status, 'an upload that cannot be stored must not look as if it had been');
        } finally {
            chmod($this->dir . '/pages', 0o755);
        }
    }
}

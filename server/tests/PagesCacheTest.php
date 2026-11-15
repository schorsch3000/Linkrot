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

    /** @return list<string> the files of pages/ */
    private function pageFiles(): array
    {
        $names = array_values(array_diff(scandir($this->dir . '/pages') ?: [], ['.', '..']));
        sort($names);

        return $names;
    }

    private function pageStore(): \Linkrot\Pages\PageStore
    {
        return new \Linkrot\Pages\PageStore($this->dir . '/pages', new \Linkrot\Pages\PageRenderer('lr.example'), fn(): int => $this->now);
    }

    public function testClearingTheCacheRemovesTheRenderedFilesAndTheIndexAndNothingElse(): void
    {
        $this->request('PUT', '/api/v1/pages/imprint', raw: "---\ntitle: Impressum\n---\nFirst\n");
        $this->request('PUT', '/api/v1/pages/other', raw: "---\ntitle: Other\n---\nSecond\n");
        // things that look similar and are not the cache
        file_put_contents($this->dir . '/pages/Notes.html', 'not a slug');
        file_put_contents($this->dir . '/pages/a.b.html', 'not a slug either');
        file_put_contents($this->dir . '/pages/readme.txt', 'mine');
        self::assertSame(['Notes.html', 'a.b.html', 'imprint.html', 'imprint.md', 'index.json', 'other.html', 'other.md', 'pages.lock', 'readme.txt'], $this->pageFiles());

        $removed = $this->pageStore()->clearCache();

        self::assertSame(3, $removed, 'index.json and two rendered pages');
        self::assertSame(['Notes.html', 'a.b.html', 'imprint.md', 'other.md', 'pages.lock', 'readme.txt'], $this->pageFiles(), 'the Markdown is the truth and stays');
    }

    public function testThePagesAreRenderedAgainAfterTheCacheWasCleared(): void
    {
        $this->request('PUT', '/api/v1/pages/imprint', raw: "---\ntitle: Impressum\nlink: Impressum\n---\nFirst\n");
        file_put_contents($this->dir . '/pages/imprint.html', '<p>STALE-OUTPUT</p>');
        $this->pageStore()->clearCache();

        $page = $this->request('GET', '/page/imprint', auth: false);

        self::assertStringContainsString('First', $page->body);
        self::assertStringNotContainsString('STALE-OUTPUT', $page->body);
        self::assertStringContainsString('href="/page/imprint"', $this->request('GET', '/file/unknown', auth: false)->body, 'the footer is back');
        self::assertFileExists($this->dir . '/pages/index.json');
        self::assertFileExists($this->dir . '/pages/imprint.html');
    }

    public function testClearingAnEmptyOrMissingCacheIsFine(): void
    {
        self::assertSame(0, $this->pageStore()->clearCache(), 'there is no pages folder yet');
        $this->request('PUT', '/api/v1/pages/imprint', raw: "---\ntitle: Impressum\n---\nx\n");
        self::assertSame(2, $this->pageStore()->clearCache());
        self::assertSame(0, $this->pageStore()->clearCache(), 'twice in a row');
    }

    public function testClearingTheCacheSaysWhatItCouldNotRemoveAndStillKeepsTheMarkdown(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root can remove anywhere');
        }
        $this->request('PUT', '/api/v1/pages/imprint', raw: "---\ntitle: Impressum\n---\nx\n");
        chmod($this->dir . '/pages', 0o555);
        try {
            $this->pageStore()->clearCache();
            self::fail('nothing was removed, that has to be said');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('imprint.html', $e->getMessage());
            self::assertStringContainsString('index.json', $e->getMessage());
        } finally {
            chmod($this->dir . '/pages', 0o755);
        }
        self::assertFileExists($this->dir . '/pages/imprint.md');
    }

    /**
     * A cache from an older renderer: the index says so, and the `.html` of the page is still there (not deleted, unlike in
     * staleCache()) with output that the current rules would not produce.
     */
    private function oldRendererCache(): void
    {
        $put = $this->request('PUT', '/api/v1/pages/imprint', raw: "---\ntitle: Impressum\nlink: Impressum\n---\n# Angaben\n\nMusterstraße 1\n");
        self::assertSame(200, $put->status);
        $index = json_decode((string) file_get_contents($this->dir . '/pages/index.json'), true);
        $index['pages'][0]['render_version'] = \Linkrot\Pages\PageRenderer::VERSION - 1;
        file_put_contents($this->dir . '/pages/index.json', json_encode($index));
        file_put_contents($this->dir . '/pages/imprint.html', '<p>OLD-RENDERER-HTML</p>');
    }

    private function assertFreshImprint(): void
    {
        $body = $this->request('GET', '/page/imprint', auth: false)->body;
        self::assertStringContainsString('Musterstraße 1', $body, 'rendered from the Markdown');
        self::assertStringNotContainsString('OLD-RENDERER-HTML', $body, 'the html of an older renderer is not served');
        $preview = $this->request('GET', '/api/v1/pages/imprint?format=html')->body;
        self::assertStringNotContainsString('OLD-RENDERER-HTML', $preview);
    }

    public function testHtmlOfAnOlderRendererIsNotServedWhileAnotherProcessMakesTheCache(): void
    {
        $this->oldRendererCache();
        $lock = fopen($this->dir . '/pages/pages.lock', 'c') ?: self::fail('open');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'the test plays the other process');
        try {
            $this->assertFreshImprint();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testHtmlOfAnOlderRendererIsNotServedWhenTheLockCannotBeOpened(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root can write anywhere');
        }
        $this->oldRendererCache();
        file_put_contents($this->dir . '/pages/pages.lock', '');
        chmod($this->dir . '/pages/pages.lock', 0o000);
        chmod($this->dir . '/pages', 0o555);
        try {
            $this->assertFreshImprint();
        } finally {
            chmod($this->dir . '/pages', 0o755);
            chmod($this->dir . '/pages/pages.lock', 0o644);
        }
    }

    public function testHtmlOfAnOlderRendererIsNotServedWhenTheCacheCannotBeWritten(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root can write anywhere');
        }
        $this->oldRendererCache();
        chmod($this->dir . '/pages', 0o555);
        chmod($this->dir . '/pages/imprint.html', 0o444);
        try {
            $this->assertFreshImprint();
        } finally {
            chmod($this->dir . '/pages', 0o755);
            chmod($this->dir . '/pages/imprint.html', 0o644);
        }
    }

    public function testAnOlderRendererCacheIsMadeAgainWhenNothingGetsInTheWay(): void
    {
        $this->oldRendererCache();

        $this->assertFreshImprint();

        self::assertStringContainsString('Musterstraße 1', (string) file_get_contents($this->dir . '/pages/imprint.html'), 'the cache was rewritten');
    }

    /** The rendered file is the point of the cache: while the index is current, it is what is served. */
    public function testAValidCacheIsServedFromItsFile(): void
    {
        $put = $this->request('PUT', '/api/v1/pages/imprint', raw: "---\ntitle: Impressum\n---\nfrom the markdown\n");
        self::assertSame(200, $put->status);
        file_put_contents($this->dir . '/pages/imprint.html', '<p>FROM-THE-CACHE-FILE</p>');

        self::assertStringContainsString('FROM-THE-CACHE-FILE', $this->request('GET', '/page/imprint', auth: false)->body);
    }
}

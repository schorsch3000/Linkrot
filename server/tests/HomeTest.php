<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Tests\Support\AppTestCase;
use Linkrot\Web\Page;

final class HomeTest extends AppTestCase
{
    public function testAboutPageAtRootNeedsNoAuthentication(): void
    {
        $response = $this->request('GET', '/', auth: false);

        self::assertSame(200, $response->status);
        self::assertStringStartsWith('text/html', $response->headers['Content-Type']);
        self::assertStringContainsString('Links that rot.', $response->body);
        self::assertStringContainsString('link<span class="rot">rot</span>', $response->body);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
    }

    public function testHeadWorksToo(): void
    {
        self::assertSame(200, $this->request('HEAD', '/', auth: false)->status);
    }

    public function testExamplesNameThisServerAndHaveCopyButtons(): void
    {
        $html = $this->request('GET', '/', auth: false)->body;

        self::assertStringContainsString('<code id="c-config">linkrot config --server https://lr.example</code>', $html);
        self::assertStringContainsString('<code id="c-up">linkrot up report.pdf</code>', $html);
        self::assertStringContainsString('<code id="c-rot">linkrot up report.pdf --rm-after 2d --uses 1</code>', $html);
        self::assertSame(3, substr_count($html, 'class="copy"'));
    }

    public function testSameStyleAndSameSecurityAsTheDownloadPage(): void
    {
        $response = $this->request('GET', '/', auth: false);
        $html = $response->body;

        self::assertSame(Page::scriptContentSecurityPolicy(), $response->headers['Content-Security-Policy']);
        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        self::assertStringContainsString('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', $html);
        self::assertSame(1, substr_count($html, '<script'));
        if (preg_match('~<script>(.*?)</script>~s', $html, $m) !== 1) {
            self::fail('no inline script');
        }
        self::assertStringContainsString("script-src 'sha256-" . base64_encode(hash('sha256', $m[1], true)) . "'", $response->headers['Content-Security-Policy']);
        foreach (['<img', '<iframe', 'url(', '@import', 'animation', 'transition', 'onclick', 'rel="stylesheet"'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html, $forbidden);
        }
    }

    public function testOnlyLinksToTheProjectAreExternal(): void
    {
        preg_match_all('~(?:src|href)="(https?://[^"]*)"~', $this->request('GET', '/', auth: false)->body, $m);

        self::assertSame(
            ['https://github.com/schorsch3000/Linkrot/releases', 'https://github.com/schorsch3000/Linkrot'],
            $m[1],
        );
    }

    public function testRevealsNothingAboutThisInstancesFiles(): void
    {
        $this->upload('secret', 'secret-name.txt');

        $html = strtolower($this->request('GET', '/', auth: false)->body);

        self::assertStringNotContainsString('secret', $html);
        self::assertStringNotContainsString('/dl/', $html);
        self::assertStringNotContainsString('/file/', $html);
    }

    public function testOtherUnknownPathsStayJson404(): void
    {
        $response = $this->request('GET', '/nope', auth: false);

        self::assertSame(404, $response->status);
        self::assertStringContainsString('"not_found"', $response->body);
    }
}

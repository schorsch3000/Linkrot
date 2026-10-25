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

    public function testNeedsNoScriptAndHasNoCopyBoxesOrCommands(): void
    {
        $response = $this->request('GET', '/', auth: false);
        $html = $response->body;

        self::assertSame(Page::staticContentSecurityPolicy(), $response->headers['Content-Security-Policy']);
        self::assertStringNotContainsString('script-src', $response->headers['Content-Security-Policy']);
        foreach (['<script', 'class="copy"', '<code', 'class="terminal"', 'token', 'Try it'] as $absent) {
            self::assertStringNotContainsString($absent, $html, $absent);
        }
    }

    public function testSameStyleAsTheDownloadPages(): void
    {
        $html = $this->request('GET', '/', auth: false)->body;

        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        self::assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        self::assertStringContainsString('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', $html);
        self::assertStringContainsString('class="card about"', $html);
        foreach (['<img', '<iframe', 'url(', '@import', 'animation', 'transition', 'onclick', 'rel="stylesheet"'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html, $forbidden);
        }
    }

    public function testTheButtonIsTheOnlyExternalLinkAndGoesToGithub(): void
    {
        $html = $this->request('GET', '/', auth: false)->body;

        preg_match_all('~(?:src|href)="(https?://[^"]*)"~', $html, $m);
        self::assertSame(['https://github.com/schorsch3000/Linkrot'], $m[1]);
        self::assertStringContainsString('<a class="button" href="https://github.com/schorsch3000/Linkrot">linkrot on GitHub</a>', $html);
    }

    public function testRevealsNothingAboutThisInstance(): void
    {
        $this->upload('secret', 'secret-name.txt');

        $html = strtolower($this->request('GET', '/', auth: false)->body);

        foreach (['secret', '/dl/', '/file/', 'lr.example'] as $leak) {
            self::assertStringNotContainsString($leak, $html, $leak);
        }
    }

    public function testOtherUnknownPathsStayJson404(): void
    {
        $response = $this->request('GET', '/nope', auth: false);

        self::assertSame(404, $response->status);
        self::assertStringContainsString('"not_found"', $response->body);
    }
}

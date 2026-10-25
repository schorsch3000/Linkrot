<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Config;
use Linkrot\DownloadMode;
use Linkrot\Tests\Support\AppTestCase;
use Linkrot\Web\HeaderTaglines;

final class DownloadTest extends AppTestCase
{
    protected function config(): Config
    {
        return new Config(baseUrl: 'https://lr.example', chunkSize: 10, maxRmAfter: '30d', downloadMode: DownloadMode::Xaccel);
    }

    public function testPageShowsNameAndSizeEscapedWithoutAuth(): void
    {
        $id = $this->upload('0123456789', '<b>"x".txt');

        $response = $this->request('GET', "/file/$id/<b>\"x\".txt", auth: false);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('&lt;b&gt;&quot;x&quot;.txt', $response->body);
        self::assertStringNotContainsString('<b>', $response->body);
        self::assertStringContainsString('10 bytes', $response->body);
        self::assertStringContainsString("href=\"/dl/$id/%3Cb%3E%22x%22.txt\"", $response->body);
        self::assertStringStartsWith('text/html', $response->headers['Content-Type']);
        self::assertStringContainsString("default-src 'none'", $response->headers['Content-Security-Policy']);
        self::assertStringContainsString('<script src="/assets/page.js" defer></script>', $response->body);
        self::assertStringContainsString("script-src 'self'", $response->headers['Content-Security-Policy']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        self::assertStringNotContainsString('expire', strtolower($response->body));
    }

    public function testWrongNameRedirectsToCanonicalUrl(): void
    {
        $id = $this->upload('abc', 'real name.txt');

        foreach (["/file/$id/wrong.txt", "/file/$id"] as $path) {
            $response = $this->request('GET', $path, auth: false);

            self::assertSame(302, $response->status);
            self::assertSame("/file/$id/real%20name.txt", $response->headers['Location']);
        }
    }

    public function testDownloadHeadersAndAccelRedirect(): void
    {
        $id = $this->upload('abc', 'Übung.txt');

        $response = $this->request('GET', "/dl/$id/ignored", auth: false);

        self::assertSame(200, $response->status);
        self::assertSame("/_files/$id", $response->headers['X-Accel-Redirect']);
        self::assertSame("attachment; filename=\"_bung.txt\"; filename*=UTF-8''%C3%9Cbung.txt", $response->headers['Content-Disposition']);
        self::assertSame('application/octet-stream', $response->headers['Content-Type']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        self::assertSame(HeaderTaglines::pick($id), $response->headers['X-Rot']);
        self::assertSame('', $response->body);
    }

    public function testUnknownIncompleteAndMalformedAreIndistinguishable(): void
    {
        [, $created] = $this->call('POST', '/api/v1/files', ['name' => 'a', 'size' => 3, 'sha256' => hash('sha256', 'abc')]);
        $incomplete = $created['id'];
        self::assertIsString($incomplete);

        $bodies = [];
        foreach (['abcd7KfRtm4pWxnZ', 'nope', $incomplete] as $id) {
            foreach (["/file/$id/a", "/dl/$id/a"] as $path) {
                $response = $this->request('GET', $path, auth: false);
                self::assertSame(404, $response->status, $path);
                $bodies[$response->body] = true;
            }
        }
        self::assertCount(1, $bodies);
    }

    public function testExpiredFileIsNotFoundAndRemoved(): void
    {
        $id = $this->upload('abc', rmAfter: '1h');
        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);
        $this->now += 3601;

        self::assertSame(404, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);
        self::assertFileDoesNotExist($this->dir . '/files/' . $id);
    }

    public function testUsesAreCountedAtomicallyAndExhaust(): void
    {
        $id = $this->upload('abc', extra: ['uses' => 2]);

        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);
        self::assertSame(1, $this->call('GET', "/api/v1/files/$id")[1]['uses_left']);
        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);
        self::assertSame(404, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);
        self::assertSame(404, $this->request('GET', "/file/$id/a.txt", auth: false)->status);
        self::assertSame(404, $this->call('GET', "/api/v1/files/$id")[0]);
    }

    public function testHeadPageAndResumedRangesDoNotConsumeUses(): void
    {
        $id = $this->upload('abcdef', extra: ['uses' => 1]);

        self::assertSame(200, $this->request('HEAD', "/dl/$id/a.txt", auth: false)->status);
        self::assertSame(200, $this->request('GET', "/file/$id/a.txt", auth: false)->status);
        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=3-'], auth: false)->status);
        self::assertSame(1, $this->call('GET', "/api/v1/files/$id")[1]['uses_left']);

        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=0-'], auth: false)->status);
        self::assertSame(404, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);
    }

    public function testPhpModeStreamsTheFile(): void
    {
        $app = new \Linkrot\App(
            new Config(baseUrl: 'https://lr.example', chunkSize: 10, downloadMode: DownloadMode::Php),
            $this->dir,
            new \Linkrot\Auth\InMemoryRateLimiter(10, 900, fn(): int => $this->now),
            fn(): int => $this->now,
        );
        $id = $this->upload('hello');

        $response = $app->handle(new \Linkrot\Http\Request('GET', "/dl/$id/a.txt"));

        self::assertSame(200, $response->status);
        self::assertSame($this->dir . '/files/' . $id, $response->streamPath);
        self::assertSame('5', $response->headers['Content-Length']);
        self::assertArrayNotHasKey('X-Accel-Redirect', $response->headers);
    }
}

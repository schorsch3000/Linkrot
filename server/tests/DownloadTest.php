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
        self::assertSame("/_files/lr.example/files/$id", $response->headers['X-Accel-Redirect']);
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

    public function testExpiredFileIsNotFoundAtOnceAndRemovedByTheCleanupAfterTheGracePeriod(): void
    {
        $id = $this->upload('abc', rmAfter: '1h');
        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);
        $this->now += 3601;

        self::assertSame(404, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);
        self::assertFileExists($this->dir . '/files/' . $id, 'a download that was authorized a moment ago may still be opening it');

        $this->now += 3600;
        $this->call('POST', '/api/v1/cleanup');
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

    public function testAnExhaustedFileIsNotFoundButStaysUntilTheCleanup(): void
    {
        $id = $this->upload('abc', extra: ['uses' => 1]);
        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);

        // a second visitor (a link preview bot) must not unlink the file under the download that took the last use
        self::assertSame(404, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);
        self::assertSame(404, $this->request('GET', "/file/$id/a.txt", auth: false)->status);
        self::assertSame(404, $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=0-'], auth: false)->status);
        self::assertSame(404, $this->call('GET', "/api/v1/files/$id")[0]);

        self::assertFileExists($this->dir . '/files/' . $id);
        self::assertSame(1, (int) \Linkrot\Database::query($this->app->db(), 'SELECT count(*) FROM files')->fetchColumn());
    }

    private function usesLeft(string $id): int
    {
        return (int) \Linkrot\Database::query($this->app->db(), 'SELECT uses_left FROM files WHERE id = ' . $this->app->db()->quote($id))->fetchColumn();
    }

    /** nginx serves `//dl/x` with the /dl/ location and passes the URI on unchanged: the app has to agree. */
    public function testRepeatedSlashesAreServedLikeTheCanonicalPath(): void
    {
        $id = $this->upload('abc');
        $server = $_SERVER;
        try {
            foreach (["//dl/$id/a.txt", "/dl//$id/a.txt", "///dl///$id///a.txt"] as $uri) {
                $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri . '?x=//y', 'REMOTE_ADDR' => '192.0.2.1'];

                $response = $this->app->handle(\Linkrot\Http\Request::fromGlobals());

                self::assertSame(200, $response->status, $uri);
                self::assertArrayHasKey('X-Accel-Redirect', $response->headers, $uri);
            }
        } finally {
            $_SERVER = $server;
        }
    }

    public function testHeadPageAndResumedRangesDoNotConsumeUses(): void
    {
        $id = $this->upload('abcdef', extra: ['uses' => 2]);
        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);
        self::assertSame(1, $this->usesLeft($id));

        self::assertSame(200, $this->request('HEAD', "/dl/$id/a.txt", auth: false)->status);
        self::assertSame(200, $this->request('GET', "/file/$id/a.txt", auth: false)->status);
        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=3-'], auth: false)->status);
        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=3-4'], auth: false)->status);
        self::assertSame(1, $this->usesLeft($id));

        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=0-'], auth: false)->status);
        self::assertSame(404, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);
    }

    public function testTheLastUseCanBeResumedWithinTheWindowButNotAfterwards(): void
    {
        $id = $this->upload('abcdef', extra: ['uses' => 1]);
        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", auth: false)->status);

        $this->now += 1800;
        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=3-'], auth: false)->status, 'an interrupted last download can continue');
        self::assertSame(404, $this->request('GET', "/dl/$id/a.txt", auth: false)->status, 'but a new download cannot start');
        self::assertSame(404, $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=0-'], auth: false)->status);

        $this->now += 1801; // 1h and a second after the use
        self::assertSame(404, $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=3-'], auth: false)->status);
    }

    public function testAResumeOutsideTheWindowCountsAsAUse(): void
    {
        $id = $this->upload('abcdef', extra: ['uses' => 3]);
        $this->request('GET', "/dl/$id/a.txt", auth: false);
        $this->now += 3601;

        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=3-'], auth: false)->status);

        self::assertSame(1, $this->usesLeft($id));
    }

    public function testARangeBeforeAnyUseCountsAsAUse(): void
    {
        $id = $this->upload('abcdef', extra: ['uses' => 2]);

        // nothing has been downloaded yet, so there is nothing to resume
        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=1-'], auth: false)->status);

        self::assertSame(1, $this->usesLeft($id));
    }

    /** @return iterable<string, array{string}> */
    public static function rangesThatAreNotASingleResume(): iterable
    {
        yield 'two ranges' => ['bytes=1-,0-0'];
        yield 'blank before the start' => ['bytes= 0-'];
        yield 'blank before a later start' => ['bytes= 3-'];
        yield 'other unit' => ['items=3-'];
        yield 'no start' => ['bytes=-3'];
        yield 'garbage' => ['bytes=x-'];
        yield 'start zero' => ['bytes=000-'];
        yield 'start zero, other case' => ['Bytes=0-'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rangesThatAreNotASingleResume')]
    public function testOnlyASingleRangeThatStartsAfterZeroResumes(string $range): void
    {
        $id = $this->upload('abcdef', extra: ['uses' => 3]);
        $this->request('GET', "/dl/$id/a.txt", auth: false);
        self::assertSame(2, $this->usesLeft($id));

        $this->request('GET', "/dl/$id/a.txt", ['range' => $range], auth: false);

        self::assertSame(1, $this->usesLeft($id));
    }

    /** @return iterable<string, array{string}> */
    public static function resumeRangesInAnyCase(): iterable
    {
        yield 'capitalized' => ['Bytes=3-'];
        yield 'upper case, closed range' => ['BYTES=3-4'];
        yield 'mixed case' => ['bYtEs=3-'];
    }

    /** The unit of a range is case-insensitive (RFC 9110) and nginx serves `Bytes=3-` as partial content: it is a resume. */
    #[\PHPUnit\Framework\Attributes\DataProvider('resumeRangesInAnyCase')]
    public function testTheUnitOfAResumeIsCaseInsensitive(string $range): void
    {
        $id = $this->upload('abcdef', extra: ['uses' => 3]);
        $this->request('GET', "/dl/$id/a.txt", auth: false);
        self::assertSame(2, $this->usesLeft($id));

        self::assertSame(200, $this->request('GET', "/dl/$id/a.txt", ['range' => $range], auth: false)->status);

        self::assertSame(2, $this->usesLeft($id), 'a continuation of the download that was counted does not take another use');
    }

    public function testPhpModeStreamsTheFile(): void
    {
        $app = new \Linkrot\App(
            new Config(baseUrl: 'https://lr.example', chunkSize: 10, downloadMode: DownloadMode::Php),
            $this->dir,
            'lr.example',
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

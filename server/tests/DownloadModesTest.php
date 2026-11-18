<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Api\DownloadController;
use Linkrot\App;
use Linkrot\Auth\InMemoryRateLimiter;
use Linkrot\Config;
use Linkrot\DownloadMode;
use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Tests\Support\AppTestCase;

/** The download modes besides xaccel: php (with ranges), xsendfile and litespeed. */
final class DownloadModesTest extends AppTestCase
{
    protected function config(): Config
    {
        return new Config(baseUrl: 'https://lr.example', chunkSize: 100, maxRmAfter: '30d', downloadMode: DownloadMode::Php);
    }

    private function sent(Response $response): string
    {
        ob_start();
        $response->send();

        return (string) ob_get_clean();
    }

    /** @param array<string, string> $server */
    private function in(DownloadMode $mode, string $path, array $server = []): Response
    {
        $app = new App(
            new Config(baseUrl: 'https://lr.example', chunkSize: 100, downloadMode: $mode),
            $this->dir,
            'lr.example',
            new InMemoryRateLimiter(10, 900, fn(): int => $this->now),
            fn(): int => $this->now,
        );

        return $app->handle(new Request('GET', $path, server: $server));
    }

    public function testPhpSendsTheWholeFileAndSaysThatItTakesRanges(): void
    {
        $id = $this->upload('hello world');

        $response = $this->request('GET', "/dl/$id/a.txt", auth: false);

        self::assertSame(200, $response->status);
        self::assertSame('11', $response->headers['Content-Length']);
        self::assertSame('bytes', $response->headers['Accept-Ranges']);
        self::assertSame($this->dir . '/files/' . $id, $response->streamPath);
        self::assertSame('hello world', $this->sent($response));
        http_response_code(200);
    }

    public function testPhpSendsASingleRange(): void
    {
        $id = $this->upload('hello world');

        $response = $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=6-'], auth: false);

        self::assertSame(206, $response->status);
        self::assertSame('bytes 6-10/11', $response->headers['Content-Range']);
        self::assertSame('5', $response->headers['Content-Length']);
        self::assertSame('world', $this->sent($response));

        $response = $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=0-4'], auth: false);
        self::assertSame('hello', $this->sent($response));
        $response = $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=-3'], auth: false);
        self::assertSame('bytes 8-10/11', $response->headers['Content-Range']);
        self::assertSame('rld', $this->sent($response));
        http_response_code(200);
    }

    public function testPhpAnswersAnUnsatisfiableRangeWith416(): void
    {
        $id = $this->upload('hello');

        $response = $this->request('GET', "/dl/$id/a.txt", ['range' => 'bytes=5-'], auth: false);

        self::assertSame(416, $response->status);
        self::assertSame('bytes */5', $response->headers['Content-Range']);
        self::assertNull($response->streamPath);
    }

    public function testPhpHeadHasTheHeadersButNoBody(): void
    {
        $id = $this->upload('hello');

        $response = $this->request('HEAD', "/dl/$id/a.txt", ['range' => 'bytes=1-'], auth: false);

        self::assertSame(206, $response->status);
        self::assertSame('4', $response->headers['Content-Length']);
        self::assertNull($response->streamPath);
    }

    public function testRangeParsing(): void
    {
        self::assertSame([0, 9], DownloadController::range('bytes=0-', 10));
        self::assertSame([3, 9], DownloadController::range('Bytes=3-99', 10), 'the end is cut to the size, the unit is case-insensitive');
        self::assertSame([7, 9], DownloadController::range('bytes=-3', 10));
        self::assertSame([0, 9], DownloadController::range('bytes=-30', 10), 'a suffix longer than the file is the file');
        self::assertFalse(DownloadController::range('bytes=10-', 10));
        self::assertFalse(DownloadController::range('bytes=-0', 10));
        self::assertFalse(DownloadController::range('bytes=0-', 0), 'an empty file has no byte to send');
        self::assertNull(DownloadController::range(null, 10));
        self::assertNull(DownloadController::range('bytes=0-1,4-5', 10), 'several ranges: the whole file');
        self::assertNull(DownloadController::range('bytes=5-2', 10), 'malformed: the whole file');
        self::assertNull(DownloadController::range('items=0-1', 10));
        self::assertNull(DownloadController::range('bytes=-', 10));
    }

    public function testXsendfileNamesThePathAndSendsNothingItself(): void
    {
        $id = $this->upload('hello');

        $response = $this->in(DownloadMode::Xsendfile, "/dl/$id/a.txt");

        self::assertSame(200, $response->status);
        self::assertSame($this->dir . '/files/' . $id, $response->headers['X-Sendfile']);
        self::assertNull($response->streamPath);
        self::assertArrayNotHasKey('Content-Length', $response->headers);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
    }

    public function testLitespeedNamesTheUriBelowTheDocumentRoot(): void
    {
        $id = $this->upload('hello');
        $root = (string) realpath(dirname($this->dir));

        $response = $this->in(DownloadMode::Litespeed, "/dl/$id/a.txt", ['DOCUMENT_ROOT' => $root]);

        self::assertSame(200, $response->status);
        self::assertSame('/' . basename($this->dir) . '/files/' . $id, $response->headers['X-LiteSpeed-Location']);
        self::assertNull($response->streamPath);
    }

    public function testLitespeedRefusesDataOutsideTheDocumentRoot(): void
    {
        $id = $this->upload('hello');
        $elsewhere = sys_get_temp_dir() . '/linkrot-docroot-' . bin2hex(random_bytes(4));
        mkdir($elsewhere);
        try {
            $response = $this->in(DownloadMode::Litespeed, "/dl/$id/a.txt", ['DOCUMENT_ROOT' => $elsewhere]);
        } finally {
            rmdir($elsewhere);
        }

        self::assertSame(500, $response->status);
        self::assertArrayNotHasKey('X-LiteSpeed-Location', $response->headers);
    }
}

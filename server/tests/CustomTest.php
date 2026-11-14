<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Config;
use Linkrot\Custom\Kind;
use Linkrot\DownloadMode;
use Linkrot\Tests\Support\AppTestCase;
use Linkrot\Web\HeaderTaglines;
use Linkrot\Web\Page;

final class CustomTest extends AppTestCase
{
    protected function config(): Config
    {
        return new Config(baseUrl: 'https://lr.example', chunkSize: 10, maxRmAfter: '30d', downloadMode: DownloadMode::Xaccel);
    }

    private function put(string $name, string $body): \Linkrot\Http\Response
    {
        return $this->request('PUT', "/api/v1/custom/$name", raw: $body);
    }

    private function customDir(): string
    {
        return $this->dir . '/custom';
    }

    public function testDownloadBeforeAnUploadGivesTheDefaultsAndWritesNothing(): void
    {
        $response = $this->request('GET', '/api/v1/custom/taglines');

        self::assertSame(200, $response->status);
        self::assertSame('false', $response->headers['X-Customized']);
        self::assertStringStartsWith('text/plain', $response->headers['Content-Type']);
        self::assertSame(implode("\n", Page::taglines()) . "\n", $response->body);
        self::assertSame(implode("\n", HeaderTaglines::all()) . "\n", $this->request('GET', '/api/v1/custom/headerlines')->body);
        self::assertDirectoryDoesNotExist($this->customDir());
    }

    public function testNeedsAuthenticationAndKnowsOnlyItsFiles(): void
    {
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            self::assertSame(401, $this->request($method, '/api/v1/custom/taglines', auth: false)->status, $method);
            self::assertSame(404, $this->request($method, '/api/v1/custom/passwd')->status, $method);
        }
    }

    public function testUploadReplacesTheTaglinesOnEveryPage(): void
    {
        $id = $this->upload('0123456789', 'a.txt');
        $response = $this->put('taglines', "  Mine one.  \n\nMine two.\r\nMine one.\n");

        self::assertSame(200, $response->status);
        self::assertSame(['name' => 'taglines', 'customized' => true, 'lines' => 2], json_decode($response->body, true));
        $get = $this->request('GET', '/api/v1/custom/taglines');
        self::assertSame('true', $get->headers['X-Customized']);
        self::assertSame("Mine one.\nMine two.\n", $get->body);

        foreach (["/file/$id/a.txt", '/', '/file/nope'] as $path) {
            self::assertMatchesRegularExpression('~<footer>Mine (one|two)\.</footer>~', $this->request('GET', $path, auth: false)->body, $path);
        }
        self::assertStringContainsString('<footer>Mine one.</footer>', $this->request('GET', '/', auth: false)->body);
    }

    public function testTaglinesAreEscaped(): void
    {
        $id = $this->upload('0123456789', 'a.txt');
        $this->put('taglines', '<script>alert(1)</script> & "x"');

        $html = $this->request('GET', "/file/$id/a.txt", auth: false)->body;
        self::assertStringContainsString('<footer>&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;x&quot;</footer>', $html);
    }

    public function testUploadReplacesTheHeaderLines(): void
    {
        $id = $this->upload('0123456789', 'a.txt');
        $this->put('headerlines', "mine=1\nmine=2\n");

        $header = $this->request('GET', "/dl/$id/a.txt", auth: false)->headers[HeaderTaglines::HEADER];
        self::assertContains($header, ['mine=1', 'mine=2']);
        self::assertSame($header, $this->request('GET', "/dl/$id/a.txt", auth: false)->headers[HeaderTaglines::HEADER], 'stable per file');
    }

    public function testResetGoesBackToTheDefaultsAndDeletesTheFile(): void
    {
        $id = $this->upload('0123456789', 'a.txt');
        $this->put('taglines', 'Mine.');
        self::assertFileExists($this->customDir() . '/taglines.txt');

        self::assertSame(204, $this->request('DELETE', '/api/v1/custom/taglines')->status);

        self::assertFileDoesNotExist($this->customDir() . '/taglines.txt');
        self::assertSame('false', $this->request('GET', '/api/v1/custom/taglines')->headers['X-Customized']);
        self::assertStringNotContainsString('Mine.', $this->request('GET', "/file/$id/a.txt", auth: false)->body);
        self::assertSame(204, $this->request('DELETE', '/api/v1/custom/taglines')->status, 'resetting twice is fine');
    }

    public function testTaglinesOffStillWins(): void
    {
        $id = $this->upload('0123456789', 'a.txt');
        $this->put('taglines', 'Mine.');
        $this->put('headerlines', 'mine=1');
        $this->request('PATCH', '/api/v1/settings', json: ['show_taglines' => false]);

        self::assertStringNotContainsString('<footer>', $this->request('GET', "/file/$id/a.txt", auth: false)->body);
        self::assertArrayNotHasKey(HeaderTaglines::HEADER, $this->request('GET', "/dl/$id/a.txt", auth: false)->headers);
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function refused(): iterable
    {
        yield 'empty' => ['taglines', '', 400, 'no usable line'];
        yield 'only blank lines' => ['taglines', " \n\n  \n", 400, 'no usable line'];
        yield 'not utf-8' => ['taglines', "ok\n\xff\xfe", 400, 'UTF-8'];
        yield 'control character' => ['taglines', "fine\nbad\x07bell", 400, 'line 2'];
        yield 'long line' => ['taglines', "fine\n" . str_repeat('x', 201), 400, 'line 2: longer than 200'];
        yield 'long header line' => ['headerlines', str_repeat('x', 121), 400, 'longer than 120'];
        yield 'non-ascii header line' => ['headerlines', "ok\nschön", 400, 'line 2: only printable ASCII'];
        yield 'tab in a header line' => ['headerlines', "a\tb", 400, 'printable ASCII'];
        yield 'too many bytes' => ['taglines', str_repeat("x\n", 40_000), 413, '65536 bytes'];
        yield 'header too many bytes' => ['headerlines', str_repeat("x\n", 9_000), 413, '16384 bytes'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testRefusedUploadsChangeNothing(string $name, string $body, int $status, string $message): void
    {
        $this->put($name, "Keep me.\n");

        $response = $this->put($name, $body);

        self::assertSame($status, $response->status);
        self::assertStringContainsString($message, json_decode($response->body, true)['error']['message']);
        self::assertSame("Keep me.\n", $this->request('GET', "/api/v1/custom/$name")->body);
    }

    public function testTooManyLinesIsRefused(): void
    {
        $lines = implode("\n", array_map(static fn(int $i): string => "t$i", range(1, 2001)));

        self::assertSame(413, $this->put('taglines', $lines)->status);
        self::assertSame(200, $this->put('taglines', implode("\n", array_slice(explode("\n", $lines), 0, 2000)))->status);
    }

    public function testManyBadLinesAreReportedWithAnEllipsis(): void
    {
        $response = $this->put('headerlines', implode("\n", array_fill(0, 8, 'schön')));

        $message = json_decode($response->body, true)['error']['message'];
        self::assertStringContainsString('line 5', $message);
        self::assertStringNotContainsString('line 6', $message);
        self::assertStringContainsString('and 3 more', $message);
    }

    public function testKindsHaveSaneLimits(): void
    {
        foreach (Kind::cases() as $kind) {
            self::assertNotEmpty($kind->defaults());
            self::assertLessThanOrEqual($kind->maxLines(), count($kind->defaults()));
            foreach ($kind->defaults() as $line) {
                self::assertLessThanOrEqual($kind->maxLineLength(), mb_strlen($line), $line);
            }
        }
    }
}

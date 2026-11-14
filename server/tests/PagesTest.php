<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Config;
use Linkrot\DownloadMode;
use Linkrot\Http\Response;
use Linkrot\Pages\PageRenderer;
use Linkrot\Pages\PageStore;
use Linkrot\Tests\Support\AppTestCase;
use Linkrot\Web\Page;

final class PagesTest extends AppTestCase
{
    protected function config(): Config
    {
        return new Config(baseUrl: 'https://lr.example', chunkSize: 10, maxRmAfter: '30d', downloadMode: DownloadMode::Xaccel);
    }

    private function put(string $slug, string $source): Response
    {
        return $this->request('PUT', "/api/v1/pages/$slug", raw: $source);
    }

    private function imprint(string $extra = ''): string
    {
        return "---\ntitle: Impressum\nlink: Impressum\norder: 10\nlang: de\n$extra---\n# Angaben\n\nMusterstraße 1\n";
    }

    public function testUploadAnswersWithTheMetaAndTheUrl(): void
    {
        $response = $this->put('impressum', $this->imprint());

        self::assertSame(200, $response->status);
        $page = json_decode($response->body, true);
        self::assertSame('impressum', $page['slug']);
        self::assertSame('Impressum', $page['title']);
        self::assertSame(10, $page['order']);
        self::assertTrue($page['published']);
        self::assertSame('de', $page['lang']);
        self::assertSame('https://lr.example/page/impressum', $page['url']);
    }

    public function testTheOriginalComesBackByteForByte(): void
    {
        $source = "---\r\ntitle: Wörter\r\n---\r\n\r\n# Überschrift\r\n\r\ntext without a final newline";
        $this->put('original', $source);

        $get = $this->request('GET', '/api/v1/pages/original');

        self::assertSame(200, $get->status);
        self::assertSame($source, $get->body);
        self::assertStringStartsWith('text/markdown', $get->headers['Content-Type']);
    }

    public function testRenderedHtmlIsAvailableForPreview(): void
    {
        $this->put('draft', "---\npublished: false\n---\n# Hi\n");

        $html = $this->request('GET', '/api/v1/pages/draft?format=html');

        self::assertSame(200, $html->status);
        self::assertStringContainsString('<h2>Hi</h2>', $html->body);
    }

    public function testPublicPageIsRenderedInTheStyleOfTheSiteWithItsLanguage(): void
    {
        $this->put('impressum', $this->imprint());

        $response = $this->request('GET', '/page/impressum', auth: false);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<html lang="de">', $response->body);
        self::assertStringContainsString('<title>Impressum · linkrot</title>', $response->body);
        self::assertStringContainsString('<h1 class="name">Impressum</h1>', $response->body);
        self::assertStringContainsString('<h2>Angaben</h2>', $response->body);
        self::assertStringContainsString('Musterstraße 1', $response->body);
        self::assertStringContainsString('<link rel="stylesheet" href="/assets/page', $response->body);
        self::assertSame(0, substr_count($response->body, '<script'));
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
    }

    public function testTheBodyOfAPageHasClassesForTheStylesheetOfTheHost(): void
    {
        $id = $this->upload('0123456789', 'a.txt');
        $this->put('imprint', $this->imprint());
        $this->put('data-protection', "x\n");

        self::assertStringContainsString('<body class="custompage custompage_imprint">', $this->request('GET', '/page/imprint', auth: false)->body);
        self::assertStringContainsString('<body class="custompage custompage_data-protection">', $this->request('GET', '/page/data-protection', auth: false)->body);
        foreach (['/', "/file/$id/a.txt", '/file/nope', '/page/missing'] as $path) {
            self::assertStringContainsString('<body>', $this->request('GET', $path, auth: false)->body, "$path is not a page of the host");
        }
    }

    public function testThePageCspAllowsHttpsAndDataImagesAndNothingElseNew(): void
    {
        $this->put('p', "![a](https://img.example/a.png)\n");

        $csp = $this->request('GET', '/page/p', auth: false)->headers['Content-Security-Policy'];

        self::assertSame("default-src 'none'; img-src 'self' https: data:; style-src 'self'; style-src-attr 'unsafe-inline'", $csp);
        self::assertSame($csp, Page::pageContentSecurityPolicy());
        self::assertStringNotContainsString('script-src', $csp);
        self::assertStringContainsString("img-src 'self'; ", Page::staticContentSecurityPolicy(), 'the other pages keep the strict policy');
    }

    public function testUnpublishedAndUnknownPagesAreTheSame404(): void
    {
        $this->put('draft', "---\npublished: false\n---\nText\n");

        $draft = $this->request('GET', '/page/draft', auth: false);
        $unknown = $this->request('GET', '/page/nothing-here', auth: false);

        self::assertSame(404, $draft->status);
        self::assertSame(404, $unknown->status);
        self::assertSame($draft->body, $unknown->body);
        self::assertStringContainsString('Nothing here.', $draft->body);
        self::assertStringNotContainsString('Text', $draft->body);
        self::assertSame(404, $this->request('GET', '/page/..%2Fetc', auth: false)->status);
        self::assertSame(404, $this->request('GET', '/page/Impressum', auth: false)->status, 'slugs are lower case');
    }

    public function testFooterLinksAreOnEveryPageInTheRightOrder(): void
    {
        $id = $this->upload('0123456789', 'a.txt');
        $this->put('privacy', "---\ntitle: Datenschutz\norder: 20\nlang: de\n---\nx\n");
        $this->put('imprint', $this->imprint());
        $this->put('about', "---\ntitle: About us\nlink: About\n---\nx\n");
        $this->put('draft', "---\npublished: false\norder: 1\n---\nx\n");
        $nav = '<nav class="pages" aria-label="Pages"><a href="/page/imprint" lang="de">Impressum</a><a href="/page/privacy" lang="de">Datenschutz</a><a href="/page/about">About</a></nav>';

        foreach (["/file/$id/a.txt", '/', '/file/nope', '/page/missing'] as $path) {
            self::assertStringContainsString($nav, $this->request('GET', $path, auth: false)->body, $path);
        }
        // the language of a link is only marked where it differs from the language of the page it is on
        foreach (['/page/imprint', '/page/about'] as $path) {
            preg_match_all('~<a href="(/page/[a-z-]+)"~', $this->request('GET', $path, auth: false)->body, $m);
            self::assertSame(['/page/imprint', '/page/privacy', '/page/about'], array_slice($m[1], -3), $path);
        }
        self::assertStringContainsString('<a href="/page/imprint">Impressum</a>', $this->request('GET', '/page/imprint', auth: false)->body);
        self::assertStringNotContainsString('/page/draft', $this->request('GET', '/', auth: false)->body);
    }

    public function testLinksWithTheSameOrderAreSortedByText(): void
    {
        $this->put('b', "---\nlink: beta\n---\nx\n");
        $this->put('a', "---\nlink: Alpha\n---\nx\n");
        $this->put('c', "---\nlink: gamma\norder: 5\n---\nx\n");

        $order = array_column(json_decode($this->request('GET', '/api/v1/pages')->body, true)['pages'], 'slug');

        self::assertSame(['c', 'a', 'b'], $order);
    }

    public function testTheEmptyStartpageStaysEmpty(): void
    {
        $this->put('imprint', $this->imprint());
        $this->request('PATCH', '/api/v1/settings', json: ['show_startpage' => false]);

        $response = $this->request('GET', '/', auth: false);

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
    }

    public function testFooterLinksStayWhenTheTaglinesAndTheTitleAreOff(): void
    {
        $this->put('imprint', $this->imprint());
        $this->request('PATCH', '/api/v1/settings', json: ['show_taglines' => false, 'show_title' => false]);

        $html = $this->request('GET', '/', auth: false)->body;

        self::assertStringContainsString('<nav class="pages"', $html);
        self::assertStringNotContainsString('<footer>', $html);
        self::assertStringNotContainsString('class="brand"', $html);
    }

    public function testNoFooterLinksWithoutPages(): void
    {
        self::assertStringNotContainsString('<nav', $this->request('GET', '/', auth: false)->body);
    }

    public function testPagesGetTheCustomTaglinesAndStylesheet(): void
    {
        $this->put('imprint', $this->imprint());
        $this->request('PUT', '/api/v1/custom/taglines', raw: 'Only mine.');
        $this->request('PUT', '/api/v1/custom/css', raw: 'a { color: red }');

        $html = $this->request('GET', '/page/imprint', auth: false)->body;

        self::assertStringContainsString('<footer>Only mine.</footer>', $html);
        self::assertMatchesRegularExpression('~href="/assets/user\.[0-9a-f]{16}\.css"~', $html);
    }

    public function testAnEtagMakesTheSecondVisitA304AndChangesWithTheContent(): void
    {
        $this->put('imprint', $this->imprint());
        $first = $this->request('GET', '/page/imprint', auth: false);
        $etag = $first->headers['ETag'];

        $again = $this->request('GET', '/page/imprint', ['if-none-match' => $etag], auth: false);

        self::assertSame('no-cache', $first->headers['Cache-Control']);
        self::assertSame(304, $again->status);
        self::assertSame('', $again->body);

        $this->put('imprint', $this->imprint() . 'more');
        self::assertSame(200, $this->request('GET', '/page/imprint', ['if-none-match' => $etag], auth: false)->status);

        $this->put('other', "---\ntitle: Other\n---\nx\n");
        $afterNewLink = $this->request('GET', '/page/imprint', ['if-none-match' => $this->request('GET', '/page/imprint', auth: false)->headers['ETag']], auth: false);
        self::assertSame(304, $afterNewLink->status);
        self::assertNotSame($etag, $this->request('GET', '/page/imprint', auth: false)->headers['ETag'], 'a new footer link is a change of the page');
    }

    public function testReplacingKeepsTheSlugAndDeleteRemovesTheFilesAndTheLink(): void
    {
        $this->put('imprint', $this->imprint());
        $this->put('imprint', "---\ntitle: New title\n---\nnew\n");

        self::assertCount(1, json_decode($this->request('GET', '/api/v1/pages')->body, true)['pages']);
        self::assertStringContainsString('New title', $this->request('GET', '/page/imprint', auth: false)->body);

        self::assertSame(204, $this->request('DELETE', '/api/v1/pages/imprint')->status);

        self::assertSame(404, $this->request('GET', '/page/imprint', auth: false)->status);
        self::assertSame(404, $this->request('GET', '/api/v1/pages/imprint')->status);
        self::assertSame([], glob($this->dir . '/pages/imprint.*') ?: []);
        self::assertStringNotContainsString('<nav', $this->request('GET', '/', auth: false)->body);
        self::assertSame(204, $this->request('DELETE', '/api/v1/pages/imprint')->status, 'deleting twice is fine');
        self::assertSame(204, $this->request('DELETE', '/api/v1/pages/not-valid-Slug')->status);
    }

    public function testNeedsAuthentication(): void
    {
        foreach ([['GET', '/api/v1/pages'], ['GET', '/api/v1/pages/x'], ['PUT', '/api/v1/pages/x'], ['DELETE', '/api/v1/pages/x'], ['GET', '/api/v1/page-template']] as [$method, $path]) {
            self::assertSame(401, $this->request($method, $path, auth: false)->status, "$method $path");
        }
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function refused(): iterable
    {
        yield 'raw html' => ['p', "<script>alert(1)</script>\n", 400, 'raw HTML'];
        yield 'javascript link' => ['p', '[x](javascript:alert(1))', 400, 'not allowed'];
        yield 'bad frontmatter' => ['p', "---\ncolor: red\n---\n", 400, 'unknown key'];
        yield 'not utf-8' => ['p', "\xff\xfe", 400, 'UTF-8'];
        yield 'nul byte' => ['p', "a\0b", 400, 'NUL'];
        yield 'too large' => ['p', str_repeat("word\n", 14_000), 413, '65536 bytes'];
        yield 'slug with capital' => ['Imprint', 'x', 400, 'name of a page'];
        yield 'slug with dot' => ['a.b', 'x', 400, 'name of a page'];
        yield 'slug with dash at the end' => ['abc-', 'x', 400, 'name of a page'];
        yield 'slug too long' => [str_repeat('a', 41), 'x', 400, 'name of a page'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testRefusedUploadsChangeNothing(string $slug, string $source, int $status, string $message): void
    {
        $this->put('keep', "---\ntitle: Keep\n---\nme\n");

        $response = $this->put($slug, $source);

        self::assertSame($status, $response->status);
        $error = json_decode($response->body, true)['error'];
        self::assertSame($status === 413 ? 'too_large' : 'invalid_request', $error['code']);
        self::assertStringContainsString($message, $error['message']);
        self::assertCount(1, json_decode($this->request('GET', '/api/v1/pages')->body, true)['pages']);
    }

    public function testARefusedReplacementKeepsTheOldPage(): void
    {
        $this->put('p', "---\ntitle: Old\n---\nold\n");
        $this->put('p', "<b>new</b>\n");

        self::assertStringContainsString('Old', $this->request('GET', '/page/p', auth: false)->body);
        self::assertStringContainsString('old', $this->request('GET', '/api/v1/pages/p')->body);
    }

    public function testAtMostTwentyPages(): void
    {
        for ($i = 1; $i <= PageStore::MAX_PAGES; ++$i) {
            self::assertSame(200, $this->put("page-$i", "x\n")->status);
        }

        $response = $this->put('one-too-many', "x\n");

        self::assertSame(400, $response->status);
        self::assertStringContainsString('at most 20 pages', json_decode($response->body, true)['error']['message']);
        self::assertSame(200, $this->put('page-7', "replaced\n")->status, 'replacing one of them is fine');
        $this->request('DELETE', '/api/v1/pages/page-1');
        self::assertSame(200, $this->put('one-too-many', "x\n")->status);
    }

    public function testTheTemplateIsAValidDraftWithEveryKey(): void
    {
        $template = $this->request('GET', '/api/v1/page-template');

        self::assertSame(200, $template->status);
        self::assertStringStartsWith('text/markdown', $template->headers['Content-Type']);
        foreach (['title:', 'link:', 'order:', 'published:', 'lang:'] as $key) {
            self::assertStringContainsString("\n$key", $template->body, $key);
        }
        $response = $this->put('template', $template->body);
        self::assertSame(200, $response->status, $response->body);
        self::assertFalse(json_decode($response->body, true)['published'], 'the template does not go live unchanged');
        $html = $this->request('GET', '/api/v1/pages/template?format=html')->body;
        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<blockquote>', $html);
        self::assertSame($template->body, $this->request('GET', '/api/v1/pages/template')->body);
    }

    public function testTheIndexIsMadeAgainFromTheSourcesWhenItIsMissingOrFromAnOlderRenderer(): void
    {
        $this->put('imprint', $this->imprint());
        $this->put('other', "---\ntitle: Other\n---\nx\n");

        unlink($this->dir . '/pages/index.json');
        unlink($this->dir . '/pages/imprint.html');
        self::assertStringContainsString('Musterstraße 1', $this->request('GET', '/page/imprint', auth: false)->body);
        self::assertCount(2, json_decode($this->request('GET', '/api/v1/pages')->body, true)['pages']);

        $index = json_decode((string) file_get_contents($this->dir . '/pages/index.json'), true);
        $index['pages'][0]['render_version'] = PageRenderer::VERSION - 1;
        file_put_contents($this->dir . '/pages/index.json', json_encode($index));
        file_put_contents($this->dir . '/pages/imprint.html', 'STALE');
        self::assertStringNotContainsString('STALE', $this->request('GET', '/page/imprint', auth: false)->body);

        file_put_contents($this->dir . '/pages/index.json', '{broken');
        self::assertCount(2, json_decode($this->request('GET', '/api/v1/pages')->body, true)['pages']);
    }

    public function testAPageThatTheRendererNoLongerAcceptsStaysDownloadableButIsNotShown(): void
    {
        $this->put('old', "---\ntitle: Old\n---\nfine\n");
        // as if an earlier release had accepted this
        file_put_contents($this->dir . '/pages/old.md', "now <b>refused</b>\n");
        unlink($this->dir . '/pages/index.json');

        self::assertSame(404, $this->request('GET', '/page/old', auth: false)->status);
        self::assertSame("now <b>refused</b>\n", $this->request('GET', '/api/v1/pages/old')->body);
        $page = json_decode($this->request('GET', '/api/v1/pages')->body, true)['pages'][0];
        self::assertSame('old', $page['slug']);
        self::assertFalse($page['published']);
    }

    public function testFooterLinkTextIsEscaped(): void
    {
        $this->put('x', "---\nlink: \"<b>&\"\n---\ntext\n");

        $html = $this->request('GET', '/', auth: false)->body;

        self::assertStringContainsString('>&lt;b&gt;&amp;</a>', $html);
        self::assertStringNotContainsString('<b>&', $html);
    }

    public function testTitleIsEscapedInTheHeadlineAndTheTabTitle(): void
    {
        $this->put('x', "---\ntitle: <script>alert(1)</script>\n---\ntext\n");

        $html = $this->request('GET', '/page/x', auth: false)->body;

        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }
}

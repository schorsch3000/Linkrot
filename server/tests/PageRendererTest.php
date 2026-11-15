<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Custom\InvalidCustomFile;
use Linkrot\Pages\PageRenderer;
use PHPUnit\Framework\TestCase;

final class PageRendererTest extends TestCase
{
    private function render(string $markdown): string
    {
        return new PageRenderer('lr.example')->render($markdown);
    }

    public function testRendersTheUsualThings(): void
    {
        $html = $this->render("Some **bold**, *italic*, ~~gone~~ and `code`.\n\n- a\n- b\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\n> quote\n");

        foreach (['<strong>bold</strong>', '<em>italic</em>', '<del>gone</del>', '<code>code</code>', '<li>a</li>', '<table>', '<th>A</th>', '<td>2</td>', '<blockquote>'] as $expected) {
            self::assertStringContainsString($expected, $html, $expected);
        }
    }

    public function testHeadingsMoveDownOneLevelAndStopAtSix(): void
    {
        $html = $this->render("# one\n\n## two\n\n###### six\n");

        self::assertStringContainsString('<h2>one</h2>', $html);
        self::assertStringContainsString('<h3>two</h3>', $html);
        self::assertStringContainsString('<h6>six</h6>', $html);
        self::assertStringNotContainsString('<h1', $html);
    }

    public function testExternalLinksGetRelAndInternalOnesNot(): void
    {
        $html = $this->render('[a](https://elsewhere.example/x) [b](https://lr.example/page/x) [c](/page/y) [d](mailto:me@example.org) <https://auto.example>');

        self::assertMatchesRegularExpression('~<a rel="nofollow noopener noreferrer" href="https://elsewhere.example/x">a</a>~', $html);
        self::assertStringContainsString('<a href="https://lr.example/page/x">b</a>', $html);
        self::assertStringContainsString('<a href="/page/y">c</a>', $html);
        self::assertStringContainsString('href="mailto:me@example.org"', $html);
        self::assertStringContainsString('href="https://auto.example"', $html);
    }

    public function testImagesAreLazyAndSendNoReferrer(): void
    {
        $html = $this->render('![logo](https://img.example/logo.png)');

        foreach (['src="https://img.example/logo.png"', 'alt="logo"', 'loading="lazy"', 'referrerpolicy="no-referrer"'] as $attribute) {
            self::assertStringContainsString($attribute, $html);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedImages(): iterable
    {
        yield 'https' => ['![a](https://x.example/a.png)'];
        yield 'png data' => ['![a](data:image/png;base64,iVBORw0KGgo=)'];
        yield 'gif data' => ['![a](data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==)'];
        yield 'jpeg data' => ['![a](data:image/jpeg;base64,/9j/4AAQ)'];
        yield 'webp data' => ['![a](data:image/webp;base64,UklGRg==)'];
        yield 'path on this server' => ['![a](/assets/user.0123456789abcdef.css)'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedImages')]
    public function testAcceptedImages(string $markdown): void
    {
        self::assertStringContainsString('<img', $this->render($markdown));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'html block' => ["Fine.\n\n<div>no</div>\n", 'line 3: raw HTML'];
        yield 'inline html' => ['A <b>bold</b> word', 'line 1: raw HTML'];
        yield 'script' => ["<script>alert(1)</script>\n", 'raw HTML'];
        yield 'comment' => ["<!-- hidden -->\n", 'raw HTML'];
        yield 'iframe' => ['<iframe src="https://x.example"></iframe>', 'raw HTML'];
        yield 'javascript link' => ['[x](javascript:alert(1))', 'link to `javascript:alert(1)` is not allowed'];
        yield 'mixed case javascript link' => ['[x](JaVaScRiPt:alert(1))', 'not allowed'];
        yield 'data link' => ['[x](data:text/html;base64,PHNjcmlwdD4=)', 'not allowed'];
        yield 'vbscript link' => ['[x](vbscript:msgbox)', 'not allowed'];
        yield 'file link' => ['[x](file:///etc/passwd)', 'not allowed'];
        yield 'protocol relative link' => ['[x](//evil.example/)', 'not allowed'];
        yield 'http image' => ['![x](http://x.example/a.png)', 'image `http://x.example/a.png` is not allowed'];
        yield 'svg data image' => ['![x](data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=)', 'not allowed'];
        yield 'data image without base64' => ['![x](data:image/png,abc)', 'not allowed'];
        yield 'javascript image' => ['![x](javascript:alert(1))', 'not allowed'];
        yield 'protocol relative image' => ['![x](//evil.example/a.png)', 'not allowed'];
        yield 'relative image without slash' => ['![x](a.png)', 'not allowed'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testRefused(string $markdown, string $message): void
    {
        try {
            $this->render($markdown);
            self::fail('accepted: ' . $markdown);
        } catch (InvalidCustomFile $e) {
            self::assertStringContainsString($message, $e->getMessage());
            self::assertSame(400, $e->status);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function disguisedDestinations(): iterable
    {
        yield 'control character before the scheme' => ['[x](&#1;javascript:alert(1))'];
        yield 'tab inside the scheme' => ['[x](java&#9;script:alert(1))'];
        yield 'line feed inside the scheme' => ['[x](java&#10;script:alert(1))'];
        yield 'raw tab inside the scheme in angle brackets' => ["[x](<java\tscript:alert(1)>)"];
        yield 'form feed before the scheme' => ["[x](<\x0cjavascript:alert(1)>)"];
        yield 'space before the scheme' => ['[x](&#32;javascript:alert(1))'];
        yield 'letter of the scheme percent-encoded' => ['[x](java%73cript:alert(1))'];
        yield 'colon percent-encoded' => ['[x](javascript%3Aalert(1))'];
        yield 'protocol relative, slash percent-encoded' => ['[x](%2F/evil.example/)'];
        yield 'reference definition' => ["[x][a]\n\n[a]: &#1;javascript:alert(1)"];
        yield 'control character anywhere' => ['[x](/a&#1;b)'];
        yield 'image with a control character' => ['![x](&#1;https://x.example/a.png)'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('disguisedDestinations')]
    public function testDisguisedDestinationsAreRefused(string $markdown): void
    {
        try {
            $html = $this->render($markdown);
            self::fail('accepted: ' . $markdown . ' as ' . $html);
        } catch (InvalidCustomFile $e) {
            self::assertStringContainsString('not allowed', $e->getMessage());
        }
    }

    /**
     * The property that matters, independent of how the check works: whatever is accepted, a browser that normalizes the
     * URL (leading control characters and spaces dropped, tab and line breaks removed) must not find a scheme in it that
     * can run code.
     */
    public function testNothingAcceptedCanBecomeACodeScheme(): void
    {
        $destinations = [];
        foreach (array_merge(range(0, 32), [160]) as $code) {
            $destinations[] = "&#$code;javascript:alert(1)";
            $destinations[] = "&#$code;data:text/html;base64,PHNjcmlwdD4=";
        }
        foreach ([9, 10, 13] as $code) {
            foreach (range(1, 9) as $at) {
                $destinations[] = substr('javascript', 0, $at) . "&#$code;" . substr('javascript', $at) . ':alert(1)';
            }
        }
        $accepted = 0;
        foreach ($destinations as $destination) {
            foreach (["[x]($destination)", "![x]($destination)"] as $markdown) {
                try {
                    $html = $this->render($markdown);
                } catch (InvalidCustomFile) {
                    continue;
                }
                ++$accepted;
                if (preg_match('~(?:href|src)="([^"]*)"~', $html, $m) !== 1) {
                    self::fail($markdown . ' gave ' . $html);
                }
                $url = preg_replace('~[\t\n\r]~', '', ltrim(html_entity_decode($m[1]), "\x00..\x20")) ?? '';
                self::assertDoesNotMatchRegularExpression('~^(javascript|data|vbscript):~i', $url, $markdown . ' became ' . $url);
            }
        }
        self::assertGreaterThan(0, count($destinations));
        self::assertSame(0, $accepted, 'none of these should be accepted at all');
    }

    /** @return iterable<string, array{string, string}> */
    public static function ordinaryDestinations(): iterable
    {
        yield 'path with an encoded space' => ['[x](/page/a%20b)', 'href="/page/a%20b"'];
        yield 'anchor' => ['[x](#top)', 'href="#top"'];
        yield 'query' => ['[x](?q=1)', 'href="?q=1"'];
        yield 'relative path' => ['[x](page.html)', 'href="page.html"'];
        yield 'encoded umlaut' => ['[x](/caf%C3%A9)', 'href="/caf%C3%A9"'];
        yield 'https with query' => ['[x](https://example.org/a?b=c&d=e)', 'href="https://example.org/a?b=c&amp;d=e"'];
        yield 'mailto' => ['[x](mailto:a@b.example)', 'href="mailto:a@b.example"'];
        yield 'tel' => ['[x](tel:+4912345)', 'href="tel:+4912345"'];
        yield 'space inside, angle brackets' => ['[x](<my page>)', 'href="my%20page"'];
        yield 'image on this server' => ['![x](/assets/a%20b.png)', 'src="/assets/a%20b.png"'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ordinaryDestinations')]
    public function testOrdinaryDestinationsStillWork(string $markdown, string $expected): void
    {
        self::assertStringContainsString($expected, $this->render($markdown));
    }

    public function testManyProblemsAreSummarized(): void
    {
        $markdown = implode("\n\n", array_fill(0, 8, '<b>x</b>'));

        try {
            $this->render($markdown);
            self::fail('accepted');
        } catch (InvalidCustomFile $e) {
            self::assertStringContainsString('and 3 more', $e->getMessage());
        }
    }

    public function testCodeBlocksKeepTheirAngleBracketsAsText(): void
    {
        $html = $this->render("```\n<script>alert(1)</script>\n```\n\n`<b>`\n");

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testNothingThatLooksLikeScriptSurvivesInTheOutputOfAcceptedInput(): void
    {
        $html = $this->render("# not html\n\n[a](https://x.example \"t\\\" onclick=\\\"alert(1)\")\n\n&lt;script&gt;\n");

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('" onclick=', $html, 'a quote in a title must stay escaped and not open a new attribute');
        self::assertStringContainsString('title="t&quot; onclick=&quot;alert(1)"', $html);
    }

    public function testVeryDeepNestingDoesNotCrash(): void
    {
        $html = $this->render(str_repeat('> ', 200) . 'deep');

        self::assertStringContainsString('deep', $html);
    }
}

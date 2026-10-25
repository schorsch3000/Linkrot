<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Files\FileRecord;
use Linkrot\Files\FileStatus;
use Linkrot\Web\FileKind;
use Linkrot\Web\Page;
use Linkrot\Web\QrCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    private function file(string $name = 'report.pdf', int $size = 1_234_567): FileRecord
    {
        return new FileRecord('abcd7KfRtm4pWxnZ', $name, $size, str_repeat('ab', 32), FileStatus::Ready, $size, 1, 1, 1, 86400, 2_000_000_000, 3);
    }

    /** @return iterable<string, array{string, FileKind, string|null}> */
    public static function kinds(): iterable
    {
        yield 'image' => ['photo.JPG', FileKind::Image, 'jpg'];
        yield 'video' => ['clip.mkv', FileKind::Video, 'mkv'];
        yield 'audio' => ['song.flac', FileKind::Audio, 'flac'];
        yield 'archive' => ['backup.zip', FileKind::Archive, 'zip'];
        yield 'compound archive' => ['backup.tar.gz', FileKind::Archive, 'tar.gz'];
        yield 'source' => ['main.go', FileKind::Code, 'go'];
        yield 'config' => ['config.yaml', FileKind::Code, 'yaml'];
        yield 'document' => ['report.pdf', FileKind::Document, 'pdf'];
        yield 'disk image' => ['debian.iso', FileKind::Disk, 'iso'];
        yield 'typescript' => ['main.ts', FileKind::Code, 'ts'];
        yield 'c64 disk' => ['game.D64', FileKind::C64, 'd64'];
        yield 'c64 cartridge' => ['game.crt', FileKind::C64, 'crt'];
        yield 'unknown extension' => ['data.xyz', FileKind::Generic, 'xyz'];
        yield 'no extension' => ['Makefile', FileKind::Generic, null];
        yield 'hidden file' => ['.bashrc', FileKind::Generic, null];
        yield 'silly extension' => ['a.some-long-extension', FileKind::Generic, null];
        yield 'hidden compound-looking name' => ['.tar.gz', FileKind::Archive, 'gz'];
        yield 'trailing dot' => ['name.', FileKind::Generic, null];
    }

    #[DataProvider('kinds')]
    public function testKindAndExtension(string $name, FileKind $kind, ?string $extension): void
    {
        self::assertSame($kind, FileKind::fromName($name));
        self::assertSame($extension, FileKind::extension($name));
    }

    public function testNoExtensionBelongsToTwoKinds(): void
    {
        $extensions = new \ReflectionMethod(FileKind::class, 'extensions');
        $owners = [];
        foreach (FileKind::cases() as $kind) {
            foreach (array_filter(explode(' ', (string) $extensions->invoke($kind))) as $extension) {
                $owners[$extension][] = $kind->value;
            }
        }

        $duplicates = array_filter($owners, static fn(array $kinds): bool => count($kinds) > 1);
        self::assertSame([], $duplicates, 'extensions listed in more than one kind');
    }

    public function testEveryKindHasAnIconHueAndGlyph(): void
    {
        foreach (FileKind::cases() as $kind) {
            self::assertGreaterThanOrEqual(0, $kind->hue());
            self::assertLessThanOrEqual(360, $kind->hue());
            if ($kind !== FileKind::Generic) {
                self::assertNotSame('', $kind->glyph(), $kind->value);
            }
        }
    }

    public function testFilePage(): void
    {
        $html = Page::file($this->file(), 'https://lr.example/');

        self::assertStringContainsString('<title>report.pdf · linkrot</title>', $html);
        self::assertStringContainsString('link<span class="rot">rot</span>', $html);
        self::assertStringContainsString('1.2 MiB', $html);
        self::assertStringContainsString('<span class="badge">.pdf</span>', $html);
        self::assertStringContainsString('href="/dl/abcd7KfRtm4pWxnZ/report.pdf"', $html);
        self::assertStringContainsString('--hue: ' . FileKind::Document->hue(), $html);
        self::assertStringContainsString('<code id="c-cli">linkrot down abcd7KfRtm4pWxnZ</code>', $html);
        self::assertStringContainsString("<code id=\"c-curl\">curl -OJ &#039;https://lr.example/dl/abcd7KfRtm4pWxnZ/report.pdf&#039;</code>", $html);
        self::assertStringContainsString('<code id="c-sha">' . str_repeat('ab', 32) . '</code>', $html);
        self::assertStringContainsString('1 234 567 bytes', $html);
    }

    public function testTheHeaderLinksToTheAboutPage(): void
    {
        foreach ([Page::file($this->file(), 'https://lr.example'), Page::notFound(), Page::home()] as $html) {
            self::assertStringContainsString('<header class="brand"><a href="/">', $html);
        }
    }

    public function testFilePageHasAQrCodeDialogForThePageItself(): void
    {
        $html = Page::file($this->file(), 'https://lr.example');

        self::assertStringContainsString('<button type="button" class="button alt" data-qr="qr" hidden>QR code</button>', $html);
        self::assertStringContainsString('<dialog class="qr" id="qr"', $html);
        self::assertStringContainsString('<svg role="img" aria-label="QR code of this page" viewBox="0 0 256 256">', $html);
        self::assertStringNotContainsString('<?xml', $html);
        self::assertStringNotContainsString('<dialog', Page::notFound());
    }

    public function testQrCodeEncodesTheText(): void
    {
        $short = QrCode::svg('https://lr.example/a', 'x');
        $long = QrCode::svg('https://lr.example/file/abcd7KfRtm4pWxnZ/report.pdf', 'x');

        self::assertStringStartsWith('<svg role="img" aria-label="x" ', $short);
        self::assertNotSame($short, $long);
        self::assertSame($short, QrCode::svg('https://lr.example/a', 'x'));
    }

    public function testFollowsTheBrowsersColourScheme(): void
    {
        $html = Page::file($this->file(), 'https://lr.example');

        self::assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        $css = $this->asset('page.css');
        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
        self::assertStringContainsString('color-scheme: light dark;', $css);
    }

    private function asset(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../htdocs/assets/' . $name);
    }

    public function testNothingIsLoadedFromAnywhereAndThereIsNoAnimation(): void
    {
        foreach ([Page::file($this->file(), 'https://lr.example'), Page::notFound(), Page::home()] as $html) {
            foreach (['rel="preload"', '<img', '<iframe', 'url(', '@import', 'animation', 'transition', '@keyframes', 'onclick', '<style'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $html, $forbidden);
            }
            if ($html !== Page::home()) { // the about page has one link to the project
                self::assertDoesNotMatchRegularExpression('~(src|href)="https?://~', $html);
            }
            self::assertSame(1, substr_count($html, 'rel="stylesheet"'));
            self::assertStringContainsString('<link rel="stylesheet" href="/assets/page.css">', $html);
        }
        foreach (['page.css', 'page.js'] as $asset) {
            $content = $this->asset($asset);
            foreach (['url(', '@import', 'animation', 'transition', '@keyframes', 'eval(', 'fetch(', 'XMLHttpRequest', 'innerHTML'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $content, "$asset: $forbidden");
            }
            self::assertDoesNotMatchRegularExpression('~https?://~', $content, "$asset loads or mentions nothing external");
        }
    }

    public function testTheOnlyScriptIsALocalFileAndTheContentSecurityPolicySaysSo(): void
    {
        $html = Page::file($this->file(), 'https://lr.example');

        self::assertSame(1, substr_count($html, '<script'));
        self::assertSame(1, substr_count($html, '<script src="/assets/page.js" defer></script>'), 'a local, deferred script');
        self::assertSame(0, substr_count(Page::notFound(), '<script'), 'no script on the not-found page');
        self::assertSame(0, substr_count(Page::home(), '<script'), 'no script on the about page');
        self::assertSame("default-src 'none'; img-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; script-src 'self'", Page::scriptContentSecurityPolicy());
        self::assertSame("default-src 'none'; img-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'", Page::staticContentSecurityPolicy());
        foreach ([Page::scriptContentSecurityPolicy(), Page::staticContentSecurityPolicy()] as $csp) {
            self::assertStringNotContainsString('unsafe-eval', $csp);
            self::assertDoesNotMatchRegularExpression("~(script-src|style-src) [^;]*'unsafe-inline'~", $csp, 'inline style attributes only, via style-src-attr');
        }
    }

    public function testEveryTerminalBoxHasACopyButtonThatStaysHiddenWithoutScript(): void
    {
        $html = Page::file($this->file(), 'https://lr.example');

        self::assertSame(3, substr_count($html, 'class="copy"'));
        self::assertSame(3, preg_match_all('~<button type="button" class="copy" data-copy="(c-[a-z]+)"[^>]* hidden>~', $html, $buttons));
        foreach ($buttons[1] as $id) {
            self::assertSame(1, substr_count($html, "<code id=\"$id\">"), "no code element for $id");
        }
        self::assertStringContainsString('.copy[hidden] { display: none; }', $this->asset('page.css'));
        self::assertStringContainsString('class="copy"', $html);
    }

    public function testFaviconIsLinkedAndAllowedByTheContentSecurityPolicy(): void
    {
        foreach ([Page::file($this->file(), 'https://lr.example'), Page::notFound()] as $html) {
            self::assertStringContainsString('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', $html);
            self::assertStringContainsString('<link rel="icon" href="/favicon.ico" sizes="any">', $html);
            self::assertStringContainsString('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', $html);
        }
        foreach ([Page::scriptContentSecurityPolicy(), Page::staticContentSecurityPolicy()] as $csp) {
            self::assertStringContainsString("default-src 'none'", $csp);
            self::assertStringContainsString("img-src 'self'", $csp, 'same-origin images only');
        }
    }

    public function testEverythingFromTheOutsideIsEscaped(): void
    {
        $html = Page::file($this->file('<script>alert(1)</script>"\'&.txt'), "https://lr.example/x'y");

        self::assertSame(0, substr_count($html, '<script>'), 'no inline script, the name is escaped');
        self::assertStringNotContainsString('alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;&quot;&#039;&amp;.txt', $html);
        self::assertStringNotContainsString("x'y/dl", $html);
    }

    public function testNeverRevealsWhenOrHowOftenALinkDies(): void
    {
        $lower = strtolower(Page::file($this->file(), 'https://lr.example') . Page::notFound());
        foreach (['expire', 'uses', 'downloads left', 'remaining'] as $word) {
            self::assertStringNotContainsString($word, $lower, $word);
        }
    }

    public function testEveryTaglineIsSafeToShow(): void
    {
        $taglines = Page::taglines();

        self::assertGreaterThanOrEqual(60, count($taglines));
        self::assertSame($taglines, array_values(array_unique($taglines)), 'duplicate tagline');
        foreach ($taglines as $tagline) {
            $lower = strtolower($tagline);
            foreach (['expire', 'uses', 'remaining', 'downloads left', 'days', 'hours', 'weeks'] as $word) {
                self::assertStringNotContainsString($word, $lower, $tagline);
            }
            self::assertDoesNotMatchRegularExpression('~\d~', $tagline, "no numbers or dates: $tagline");
            self::assertLessThanOrEqual(80, strlen($tagline), "too long: $tagline");
            self::assertMatchesRegularExpression('~[.!?]$~', $tagline, "no final punctuation: $tagline");
            self::assertSame(trim($tagline), $tagline);
            self::assertStringNotContainsString('<', $tagline);
        }
    }

    public function testTaglineIsStablePerFileAndVaries(): void
    {
        self::assertSame(Page::tagline('abcd7KfRtm4pWxnZ'), Page::tagline('abcd7KfRtm4pWxnZ'));
        $seen = [];
        foreach (range(1, 300) as $i) {
            $seen[Page::tagline("file-$i")] = true;
        }
        self::assertGreaterThan(30, count($seen), 'taglines should be spread out over many files');
    }

    public function testNotFoundPageGivesNothingAway(): void
    {
        $html = Page::notFound();

        self::assertStringContainsString('Nothing here.', $html);
        self::assertStringNotContainsString('abcd7KfRtm4pWxnZ', $html);
        self::assertSame($html, Page::notFound());
    }

    public function testMarkupIsWellFormedEnough(): void
    {
        $html = Page::file($this->file('a.zip'), 'https://lr.example');

        foreach (['article', 'main', 'header', 'footer', 'section', 'svg', 'style', 'head', 'body', 'html'] as $tag) {
            self::assertSame(preg_match_all("~<{$tag}[\\s>]~", $html), substr_count($html, "</$tag>"), "unbalanced <$tag>");
        }
    }
}

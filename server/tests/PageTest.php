<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Files\FileRecord;
use Linkrot\Files\FileStatus;
use Linkrot\Web\FileKind;
use Linkrot\Web\Page;
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
        self::assertStringContainsString('<code>linkrot down abcd7KfRtm4pWxnZ</code>', $html);
        self::assertStringContainsString("<code>curl -OJ &#039;https://lr.example/dl/abcd7KfRtm4pWxnZ/report.pdf&#039;</code>", $html);
        self::assertStringContainsString('<code>' . str_repeat('ab', 32) . '</code>', $html);
        self::assertStringContainsString('1 234 567 bytes', $html);
    }

    public function testFollowsTheBrowsersColourScheme(): void
    {
        $html = Page::file($this->file(), 'https://lr.example');

        self::assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        self::assertStringContainsString('color-scheme: light dark;', $html);
    }

    public function testNothingIsLoadedFromAnywhereAndThereIsNoScriptOrAnimation(): void
    {
        foreach ([Page::file($this->file(), 'https://lr.example'), Page::notFound()] as $html) {
            foreach (['<script', '<link', '<img', '<iframe', 'url(', '@import', 'animation', 'transition', '@keyframes', 'onclick'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $html, $forbidden);
            }
            self::assertDoesNotMatchRegularExpression('~(src|href)="https?://~', $html);
        }
    }

    public function testEverythingFromTheOutsideIsEscaped(): void
    {
        $html = Page::file($this->file('<script>alert(1)</script>"\'&.txt'), "https://lr.example/x'y");

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;&quot;&#039;&amp;.txt', $html);
        self::assertStringNotContainsString("x'y/dl", $html);
    }

    public function testNeverRevealsWhenOrHowOftenALinkDies(): void
    {
        $lower = strtolower(Page::file($this->file(), 'https://lr.example') . Page::notFound());
        foreach (['expire', 'uses', 'downloads left', 'remaining'] as $word) {
            self::assertStringNotContainsString($word, $lower, $word);
        }
        foreach (range(1, 200) as $i) {
            $tagline = strtolower(Page::tagline("id-$i"));
            self::assertStringNotContainsString('expire', $tagline);
            self::assertDoesNotMatchRegularExpression('~\d~', $tagline, 'no numbers or dates in taglines');
        }
    }

    public function testTaglineIsStablePerFileAndVaries(): void
    {
        self::assertSame(Page::tagline('abcd7KfRtm4pWxnZ'), Page::tagline('abcd7KfRtm4pWxnZ'));
        $seen = [];
        foreach (range(1, 100) as $i) {
            $seen[Page::tagline("file-$i")] = true;
        }
        self::assertGreaterThan(3, count($seen));
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

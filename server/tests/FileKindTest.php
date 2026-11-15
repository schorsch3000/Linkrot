<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Web\FileKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileKindTest extends TestCase
{
    /** @return iterable<string, array{string, string}> file name, kind */
    public static function names(): iterable
    {
        // what was there before
        yield 'photo' => ['photo.JPG', 'image'];
        yield 'video' => ['clip.mkv', 'video'];
        yield 'audio' => ['song.flac', 'audio'];
        yield 'archive' => ['backup.zip', 'archive'];
        yield 'compound archive' => ['backup.tar.gz', 'archive'];
        yield 'source' => ['main.go', 'code'];
        yield 'config' => ['config.yaml', 'code'];
        yield 'pdf' => ['report.pdf', 'document'];
        yield 'text' => ['notes.txt', 'document'];
        yield 'disk image' => ['debian.iso', 'disk'];
        yield 'c64 disk' => ['game.D64', 'c64'];
        yield 'c64 cartridge' => ['game.crt', 'c64'];
        yield 'unknown' => ['data.xyz', 'generic'];
        yield 'no extension' => ['Makefile', 'generic'];

        // 3D: meshes, CAD, and what goes to the printer
        yield 'stl' => ['benchy.stl', 'mesh'];
        yield '3mf' => ['bracket.3MF', 'mesh'];
        yield 'obj' => ['model.obj', 'mesh'];
        yield 'glb' => ['scene.glb', 'mesh'];
        yield 'blender' => ['scene.blend', 'mesh'];
        yield 'step' => ['assembly.step', 'cad'];
        yield 'stp' => ['assembly.STP', 'cad'];
        yield 'iges' => ['part.iges', 'cad'];
        yield 'freecad' => ['part.FCStd', 'cad'];
        yield 'fusion' => ['part.f3d', 'cad'];
        yield 'dxf' => ['plate.dxf', 'cad'];
        yield 'gcode' => ['benchy.gcode', 'gcode'];
        yield 'bgcode' => ['benchy.bgcode', 'gcode'];
        yield 'gco' => ['benchy.gco', 'gcode'];
        yield 'openscad is code' => ['bracket.scad', 'code'];
        yield 'a compressed model is an archive' => ['benchy.stl.gz', 'archive'];

        // office, split by what it is
        yield 'spreadsheet' => ['budget.xlsx', 'sheet'];
        yield 'csv' => ['export.csv', 'sheet'];
        yield 'ods' => ['budget.ods', 'sheet'];
        yield 'slides' => ['talk.pptx', 'slides'];
        yield 'keynote' => ['talk.key', 'slides'];
        yield 'epub' => ['book.epub', 'ebook'];
        yield 'comic' => ['issue-1.cbz', 'ebook'];
        yield 'word' => ['letter.docx', 'document'];

        // the rest
        yield 'sqlite' => ['app.sqlite', 'database'];
        yield 'sql dump' => ['dump.sql', 'database'];
        yield 'parquet' => ['events.parquet', 'database'];
        yield 'age' => ['secret.txt.age', 'secret'];
        yield 'pgp' => ['mail.gpg', 'secret'];
        yield 'keepass' => ['passwords.kdbx', 'secret'];
        yield 'certificate' => ['site.pem', 'secret'];
        yield 'font' => ['inter.woff2', 'font'];
        yield 'truetype' => ['inter.TTF', 'font'];
        yield 'track' => ['ride.gpx', 'map'];
        yield 'kml' => ['places.kml', 'map'];
        yield 'subtitles' => ['movie.srt', 'subtitles'];
        yield 'webvtt' => ['movie.vtt', 'subtitles'];
        yield 'program' => ['setup.exe', 'program'];
        yield 'deb' => ['tool_1.0_amd64.deb', 'program'];
        yield 'dmg' => ['App.dmg', 'program'];
        yield 'apk' => ['app.apk', 'program'];
        yield 'virtual disk' => ['vm.vhdx', 'disk'];
        yield 'nes rom' => ['game.nes', 'retro'];
        yield 'gba rom' => ['game.gba', 'retro'];
        yield 'amiga' => ['workbench.adf', 'retro'];
    }

    #[DataProvider('names')]
    public function testTheKindOfAFileName(string $name, string $kind): void
    {
        self::assertSame($kind, FileKind::fromName($name)->value);
    }

    public function testEveryExtensionBelongsToExactlyOneKindAndFindsIt(): void
    {
        $seen = [];
        foreach (FileKind::cases() as $kind) {
            foreach ($kind->knownExtensions() as $extension) {
                self::assertMatchesRegularExpression('~^[a-z0-9]{1,8}$~D', $extension, "{$kind->value}: $extension");
                self::assertArrayNotHasKey($extension, $seen, "$extension is in {$kind->value} and in " . ($seen[$extension] ?? ''));
                $seen[$extension] = $kind->value;
                self::assertSame($kind, FileKind::fromName("file.$extension"), $extension);
            }
        }
        self::assertGreaterThan(150, count($seen));
    }

    public function testGenericIsWhatIsLeftAndHasNoGlyph(): void
    {
        self::assertSame([], FileKind::Generic->knownExtensions());
        self::assertSame('', FileKind::Generic->glyph());
    }

    public function testEveryOtherKindHasADistinctGlyphThatIsSafeToInline(): void
    {
        $glyphs = [];
        foreach (FileKind::cases() as $kind) {
            if ($kind === FileKind::Generic) {
                continue;
            }
            $glyph = $kind->glyph();
            self::assertNotSame('', $glyph, $kind->value);
            self::assertArrayNotHasKey($glyph, $glyphs, "{$kind->value} draws the same as " . ($glyphs[$glyph] ?? ''));
            $glyphs[$glyph] = $kind->value;

            // well-formed, and nothing but drawing elements and plain attributes: it is put into the page as it is
            $dom = new \DOMDocument();
            self::assertTrue($dom->loadXML('<svg xmlns="http://www.w3.org/2000/svg">' . $glyph . '</svg>'), "{$kind->value} is not well-formed");
            foreach (new \DOMXPath($dom)->query('//*') ?: [] as $element) {
                self::assertInstanceOf(\DOMElement::class, $element);
                if ($element->tagName === 'svg') {
                    continue;
                }
                self::assertContains($element->tagName, ['path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'g'], "{$kind->value}: <{$element->tagName}>");
                foreach ($element->attributes ?? [] as $attribute) {
                    self::assertMatchesRegularExpression('~^(d|cx|cy|r|rx|ry|x|y|x1|x2|y1|y2|width|height|points|transform|fill|stroke|stroke-width|stroke-dasharray|stroke-linecap|stroke-linejoin)$~D', $attribute->name, "{$kind->value}: {$attribute->name}");
                    self::assertDoesNotMatchRegularExpression('~(url\(|javascript|<|>|&)~i', $attribute->value, "{$kind->value}: {$attribute->name}");
                }
            }
        }
    }

    /** @return iterable<string, array{list<string>, int}> kinds of one family, their hue */
    public static function families(): iterable
    {
        yield '3D and what goes to the printer' => [['mesh', 'cad', 'gcode'], 310];
        yield 'documents' => [['document', 'sheet', 'slides', 'ebook'], 215];
        yield 'disks and programs' => [['disk', 'program'], 255];
        yield 'archives and secrets' => [['archive', 'secret'], 32];
        yield 'video and subtitles' => [['video', 'subtitles'], 345];
        yield 'data, maps, fonts' => [['database', 'map', 'font'], 100];
        yield 'the 8 bit corner' => [['c64', 'retro'], 60];
    }

    /** @param list<string> $kinds */
    #[DataProvider('families')]
    public function testKindsOfAFamilyShareTheirHueAndOnlyTheirGlyphTellsThemApart(array $kinds, int $hue): void
    {
        foreach ($kinds as $kind) {
            self::assertSame($hue, FileKind::from($kind)->hue(), $kind);
        }
    }

    public function testTheFamiliesStayApartOnTheColourCircle(): void
    {
        $hues = [];
        foreach (FileKind::cases() as $kind) {
            if ($kind !== FileKind::Generic) {
                $hues[$kind->hue()] = true;
            }
        }
        $hues = array_keys($hues);
        sort($hues);
        $smallest = 360;
        foreach ($hues as $i => $hue) {
            $next = $hues[($i + 1) % count($hues)];
            $smallest = min($smallest, ($next - $hue + 360) % 360);
        }
        self::assertGreaterThanOrEqual(20, $smallest, 'two families are too close to tell apart by colour');
        self::assertCount(10, $hues, 'one hue per family');
    }
}

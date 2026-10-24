<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use PHPUnit\Framework\TestCase;

/** The icons are static files in htdocs: nginx serves them directly, they are part of the release. */
final class FaviconTest extends TestCase
{
    private const string HTDOCS = __DIR__ . '/../htdocs/';

    public function testSvgIsValidXmlWithoutScript(): void
    {
        $svg = file_get_contents(self::HTDOCS . 'favicon.svg');
        self::assertIsString($svg);

        $xml = simplexml_load_string($svg);
        self::assertNotFalse($xml);
        self::assertSame('svg', $xml->getName());
        self::assertStringContainsString('viewBox="0 0 32 32"', $svg);
        self::assertStringNotContainsString('<script', $svg);
        self::assertStringNotContainsString('href=', $svg, 'no external references');
    }

    public function testIcoHasSixteenThirtyTwoAndFortyEightPixelImages(): void
    {
        $ico = file_get_contents(self::HTDOCS . 'favicon.ico');
        self::assertIsString($ico);

        $header = unpack('vreserved/vtype/vcount', $ico);
        self::assertIsArray($header);
        self::assertSame(0, $header['reserved']);
        self::assertSame(1, $header['type']);
        self::assertSame(3, $header['count']);
        $sizes = [];
        for ($i = 0; $i < 3; ++$i) {
            $entry = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vbytes/Voffset', $ico, 6 + 16 * $i);
            self::assertIsArray($entry);
            $sizes[] = $entry['width'];
            self::assertSame("\x89PNG", substr($ico, $entry['offset'], 4), 'every image is a PNG');
            self::assertLessThanOrEqual(strlen($ico), $entry['offset'] + $entry['bytes']);
        }
        self::assertSame([16, 32, 48], $sizes);
    }

    public function testAppleTouchIcon(): void
    {
        $info = getimagesize(self::HTDOCS . 'apple-touch-icon.png');

        self::assertIsArray($info);
        self::assertSame([180, 180, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
    }
}

<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Web\HeaderTaglines;
use PHPUnit\Framework\TestCase;

final class HeaderTaglinesTest extends TestCase
{
    public function testEveryLineIsAPlainHeaderValue(): void
    {
        foreach (HeaderTaglines::all() as $line) {
            self::assertMatchesRegularExpression('~^[\x21-\x7e]([\x20-\x7e]*[\x21-\x7e])?$~', $line, $line);
        }
        self::assertMatchesRegularExpression('~^[A-Za-z0-9-]+$~', HeaderTaglines::HEADER);
    }

    public function testLinesAreUnique(): void
    {
        self::assertCount(count(HeaderTaglines::all()), array_unique(HeaderTaglines::all()));
    }

    public function testAFileAlwaysGetsTheSameLine(): void
    {
        self::assertSame(HeaderTaglines::pick('abcd7KfRtm4pWxnZ'), HeaderTaglines::pick('abcd7KfRtm4pWxnZ'));
        self::assertContains(HeaderTaglines::pick('abcd7KfRtm4pWxnZ'), HeaderTaglines::all());
    }

    public function testDifferentFilesSpreadOverTheLines(): void
    {
        $seen = [];
        for ($i = 0; $i < 400; ++$i) {
            $seen[HeaderTaglines::pick('file' . $i)] = true;
        }
        self::assertCount(count(HeaderTaglines::all()), $seen);
    }
}

<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use InvalidArgumentException;
use Linkrot\Duration;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    public function testUnits(): void
    {
        self::assertSame(3600, Duration::parse('1h'));
        self::assertSame(2 * 86400, Duration::parse('2d'));
        self::assertSame(3 * 7 * 86400, Duration::parse('3w'));
        self::assertSame(4 * 30 * 86400, Duration::parse('4m'));
        self::assertSame(5 * 365 * 86400, Duration::parse('5y'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'no unit' => ['5'];
        yield 'no number' => ['h'];
        yield 'minutes' => ['5min'];
        yield 'seconds' => ['5s'];
        yield 'zero' => ['0h'];
        yield 'leading zero' => ['01h'];
        yield 'negative' => ['-1h'];
        yield 'uppercase' => ['1H'];
        yield 'fraction' => ['1.5h'];
        yield 'whitespace' => [' 1h'];
        yield 'too many digits' => ['9999999y'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalid(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Duration::parse($value);
    }

    public function testCap(): void
    {
        self::assertSame(365 * 86400, Duration::parseWithin('1y', '1y'));
        self::assertSame(1000 * 365 * 86400, Duration::parseWithin('1000y', '1000y'));

        $this->expectException(InvalidArgumentException::class);
        Duration::parseWithin('366d', '1y');
    }
}

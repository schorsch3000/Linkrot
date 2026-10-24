<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\FileId;
use PHPUnit\Framework\TestCase;

final class FileIdTest extends TestCase
{
    public function testAlphabetSizesAndNoLookalikes(): void
    {
        self::assertSame(32, strlen(FileId::LOWER));
        self::assertSame(56, strlen(FileId::FULL));
        foreach (str_split('10loIO') as $bad) {
            self::assertStringNotContainsString($bad, FileId::FULL);
        }
        self::assertCount(56, array_unique(str_split(FileId::FULL)));
    }

    public function testGenerate(): void
    {
        $seen = [];
        for ($i = 0; $i < 500; ++$i) {
            $id = FileId::generate();
            self::assertTrue(FileId::isValid($id), $id);
            self::assertMatchesRegularExpression('~^[2-9a-km-np-z]{4}[2-9a-km-np-zA-HJ-NP-Z]{12}$~', $id);
            $seen[$id] = true;
        }
        self::assertCount(500, $seen);
    }

    public function testUppercaseAppearsAfterFirstFour(): void
    {
        $upper = false;
        for ($i = 0; $i < 200 && !$upper; ++$i) {
            $upper = preg_match('~[A-Z]~', FileId::generate()) === 1;
        }
        self::assertTrue($upper);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function validity(): iterable
    {
        yield 'valid' => ['abcd7KfRtm4pWxnZ', true];
        yield 'uppercase in first four' => ['Abcd7KfRtm4pWxnZ', false];
        yield 'too short' => ['abcd7KfRtm4pWxn', false];
        yield 'too long' => ['abcd7KfRtm4pWxnZZ', false];
        yield 'lookalike 1' => ['abcd7KfRtm4pWxn1', false];
        yield 'lookalike O' => ['abcd7KfRtm4pWxnO', false];
        yield 'path chars' => ['abcd/KfRtm4pWxnZ', false];
        yield 'empty' => ['', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validity')]
    public function testIsValid(string $id, bool $expected): void
    {
        self::assertSame($expected, FileId::isValid($id));
    }

    public function testPrefix(): void
    {
        self::assertTrue(FileId::isValidPrefix('abcd'));
        self::assertTrue(FileId::isValidPrefix('abcd7K'));
        self::assertFalse(FileId::isValidPrefix('abc'));
        self::assertFalse(FileId::isValidPrefix('ABCD'));
        self::assertFalse(FileId::isValidPrefix('abcd7K1'));
    }
}

<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\FileName;
use PHPUnit\Framework\TestCase;

final class FileNameTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function names(): iterable
    {
        yield 'plain' => ['report.pdf', 'report.pdf'];
        yield 'unix path' => ['/etc/passwd', 'passwd'];
        yield 'dir traversal' => ['../../x/y.txt', 'y.txt'];
        yield 'windows path' => ['C:\\dir\\file.txt', 'file.txt'];
        yield 'control chars' => ["a\x00b\nc\x7f.txt", 'abc.txt'];
        yield 'dot' => ['.', null];
        yield 'dotdot' => ['..', null];
        yield 'empty' => ['', null];
        yield 'only slash' => ['dir/', null];
        yield 'spaces' => ['  a b.txt  ', 'a b.txt'];
        yield 'unicode' => ['Übung ✓.txt', 'Übung ✓.txt'];
        yield 'invalid utf8' => ["\xff\xfe.txt", null];
        yield 'hidden file' => ['.bashrc', '.bashrc'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('names')]
    public function testSanitize(string $input, ?string $expected): void
    {
        self::assertSame($expected, FileName::sanitize($input));
    }

    public function testSanitizeLimitsLengthOnCharacterBoundary(): void
    {
        $result = FileName::sanitize(str_repeat('ä', 300));

        self::assertNotNull($result);
        self::assertLessThanOrEqual(FileName::MAX_BYTES, strlen($result));
        self::assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    public function testContentDisposition(): void
    {
        self::assertSame(
            "attachment; filename=\"report.pdf\"; filename*=UTF-8''report.pdf",
            FileName::contentDisposition('report.pdf'),
        );
        self::assertSame(
            "attachment; filename=\"_bung _.txt\"; filename*=UTF-8''%C3%9Cbung%20%E2%9C%93.txt",
            FileName::contentDisposition('Übung ✓.txt'),
        );
        $header = FileName::contentDisposition('a"b\\c%d;e.txt');
        self::assertStringStartsWith('attachment; filename="a_b_c_d_e.txt";', $header);
    }

    public function testUrlSegment(): void
    {
        self::assertSame('a%20b%2Fc.txt', FileName::urlSegment('a b/c.txt'));
    }
}

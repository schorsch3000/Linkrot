<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use InvalidArgumentException;
use Linkrot\Tenants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TenantsTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/linkrot-tenants-' . bin2hex(random_bytes(6)) . '.yaml';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function hostHeaders(): iterable
    {
        yield 'plain' => ['files.example.com', 'files.example.com'];
        yield 'case' => ['Files.EXAMPLE.com', 'files.example.com'];
        yield 'port' => ['files.example.com:8443', 'files.example.com'];
        yield 'trailing dot' => ['files.example.com.', 'files.example.com'];
        yield 'ipv4' => ['127.0.0.1:8080', '127.0.0.1'];
        yield 'empty' => ['', null];
        yield 'traversal' => ['..', null];
        yield 'slash' => ['a/b', null];
        yield 'dotdot inside' => ['a..b', null];
        yield 'leading dot' => ['.example.com', null];
        yield 'leading dash' => ['-a.example.com', null];
        yield 'space' => ['a b', null];
        yield 'nul' => ["a\0b", null];
        yield 'ipv6' => ['[::1]:80', null];
        yield 'userinfo' => ['user@example.com', null];
        yield 'too long' => [str_repeat('a', 254), null];
    }

    #[DataProvider('hostHeaders')]
    public function testNormalize(string $header, ?string $expected): void
    {
        self::assertSame($expected, Tenants::normalize($header));
    }

    public function testEntriesMustBeNormalized(): void
    {
        file_put_contents($this->file, "hosts:\n  - B.example.org\n");
        // otherwise the list names a folder the server would never look in
        $this->expectException(InvalidArgumentException::class);
        Tenants::load($this->file);
    }

    public function testMatchOnlyListedHosts(): void
    {
        file_put_contents($this->file, "hosts:\n  - a.example\n  - b.example.org\n");
        $tenants = Tenants::load($this->file);

        self::assertSame(['a.example', 'b.example.org'], $tenants->hosts);
        self::assertSame('a.example', $tenants->match('A.example:443'));
        self::assertNull($tenants->match('c.example'));
        self::assertNull($tenants->match('../a.example'));
        self::assertNull($tenants->match(''));
    }

    /** @return iterable<string, array{string}> */
    public static function brokenFiles(): iterable
    {
        yield 'no hosts' => ["other: 1\n"];
        yield 'empty list' => ["hosts: []\n"];
        yield 'not a list' => ["hosts:\n  a: b\n"];
        yield 'not a string' => ["hosts:\n  - 12\n"];
        yield 'path' => ["hosts:\n  - ../etc\n"];
        yield 'port' => ["hosts:\n  - a.example:8080\n"];
        yield 'twice' => ["hosts:\n  - a.example\n  - a.example\n"];
        yield 'unknown key' => ["hosts:\n  - a.example\nfoo: 1\n"];
        yield 'not yaml' => ["hosts: [unclosed\n"];
        yield 'scalar' => ["just a string\n"];
    }

    #[DataProvider('brokenFiles')]
    public function testBrokenFilesAreRejected(string $content): void
    {
        file_put_contents($this->file, $content);
        $this->expectException(InvalidArgumentException::class);
        Tenants::load($this->file);
    }

    public function testMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Tenants::load($this->file);
    }

    public function testEnroll(): void
    {
        self::assertTrue(Tenants::enroll($this->file, 'A.example:8080'));
        self::assertFalse(Tenants::enroll($this->file, 'a.example'));
        self::assertTrue(Tenants::enroll($this->file, 'b.example'));

        self::assertSame(['a.example', 'b.example'], Tenants::load($this->file)->hosts);
    }

    public function testEnrollRejectsPaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Tenants::enroll($this->file, '../etc');
    }

    public function testSelect(): void
    {
        $one = new Tenants(['a.example']);
        self::assertSame('a.example', $one->select(null));
        self::assertSame('a.example', $one->select('A.example'));

        $two = new Tenants(['a.example', 'b.example']);
        self::assertSame('b.example', $two->select('b.example'));
        try {
            $two->select(null);
            self::fail('ambiguous');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('a.example, b.example', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $two->select('c.example');
    }
}

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
        $this->file = sys_get_temp_dir() . '/linkrot-tenants-' . bin2hex(random_bytes(6)) . '.php';
    }

    private function write(string $hosts): void
    {
        file_put_contents($this->file, "<?php return ['hosts' => $hosts];\n");
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
        $this->write("['B.example.org']");
        // otherwise the list names a folder the server would never look in
        $this->expectException(InvalidArgumentException::class);
        Tenants::load($this->file);
    }

    public function testMatchOnlyListedHosts(): void
    {
        $this->write("['a.example', 'b.example.org']");
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
        yield 'no hosts' => ["<?php return ['other' => 1];"];
        yield 'empty list' => ["<?php return ['hosts' => []];"];
        yield 'not a list' => ["<?php return ['hosts' => ['a' => 'b']];"];
        yield 'not a string' => ["<?php return ['hosts' => [12]];"];
        yield 'path' => ["<?php return ['hosts' => ['../etc']];"];
        yield 'port' => ["<?php return ['hosts' => ['a.example:8080']];"];
        yield 'twice' => ["<?php return ['hosts' => ['a.example', 'a.example']];"];
        yield 'unknown key' => ["<?php return ['hosts' => ['a.example'], 'foo' => 1];"];
        yield 'not an array' => ["<?php return 'just a string';"];
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

    public function testOutputOfTheGlobalConfigIsDiscardedAndAnErrorForTheCheckTools(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'linkrot-global-') ?: self::fail('tempnam');
        file_put_contents($file, "\xEF\xBB\xBF<?php return ['hosts' => ['lr.example']];\n");
        try {
            self::assertSame(['lr.example'], Tenants::load($file)->hosts);
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('prints output');
            Tenants::load($file, strict: true);
        } finally {
            unlink($file);
        }
    }
}

<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use InvalidArgumentException;
use Linkrot\Config;
use Linkrot\DownloadMode;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = Config::fromArray([], 'https://linkrot.example');

        self::assertSame(32 * 1024 * 1024, $config->chunkSize);
        self::assertSame('1w', $config->defaultRmAfter);
        self::assertSame('1y', $config->maxRmAfter);
        self::assertNull($config->defaultUses);
        self::assertSame(DownloadMode::Xaccel, $config->downloadMode);
    }

    public function testOverrides(): void
    {
        $config = Config::fromArray([
            'download_mode' => 'php',
            'default_uses' => 3,
            'max_rm_after' => '1000y',
        ], 'http://localhost:8080');

        self::assertSame(DownloadMode::Php, $config->downloadMode);
        self::assertSame(3, $config->defaultUses);
        self::assertSame('1000y', $config->maxRmAfter);
    }

    /** @return iterable<string, array{string, ?string, string}> host, Host header, expected base URL */
    public static function baseUrls(): iterable
    {
        yield 'https' => ['linkrot.example', null, 'https://linkrot.example'];
        yield 'a port of the request is not used' => ['linkrot.example', 'linkrot.example:8443', 'https://linkrot.example'];
        yield 'localhost' => ['localhost', null, 'http://localhost'];
        yield 'localhost with port' => ['localhost', 'localhost:8080', 'http://localhost:8080'];
        yield 'loopback with port' => ['127.0.0.1', '127.0.0.1:8080', 'http://127.0.0.1:8080'];
        yield 'header of another host is not used' => ['localhost', 'evil.example:8080', 'http://localhost'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('baseUrls')]
    public function testBaseUrlIsBuiltFromTheHost(string $host, ?string $authority, string $expected): void
    {
        self::assertSame($expected, Config::baseUrlFor($host, $authority));
        self::assertSame($expected, Config::fromArray([], Config::baseUrlFor($host, $authority))->baseUrl);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'base_url is gone' => [['base_url' => 'https://x']];
        yield 'unknown key' => [['nope' => 1]];
        yield 'chunk too small' => [['chunk_size' => 1024]];
        yield 'chunk too big' => [['chunk_size' => 200_000_000]];
        yield 'bad mode' => [['download_mode' => 'sendfile']];
        yield 'uses zero' => [['default_uses' => 0]];
        yield 'wrong type' => [['chunk_size' => '32']];
    }

    /** @param array<string, mixed> $values */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalid(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        Config::fromArray($values, 'https://x');
    }

    public function testLoadReadsAPhpFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'linkrot-config-') ?: self::fail('tempnam');
        file_put_contents($file, "<?php return ['default_uses' => 2];\n");
        try {
            $config = Config::load($file, 'https://lr.example');

            self::assertSame('https://lr.example', $config->baseUrl);
            self::assertSame(2, $config->defaultUses);
        } finally {
            unlink($file);
        }
    }

    public function testLoadReportsAMissingFileAndAFileThatReturnsNoArray(): void
    {
        try {
            Config::load('/nonexistent/linkrot/config.php', 'https://lr.example');
            self::fail('a missing file must be reported');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('file not found', $e->getMessage());
        }

        $file = tempnam(sys_get_temp_dir(), 'linkrot-config-') ?: self::fail('tempnam');
        file_put_contents($file, "<?php return 'oops';\n");
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('must return an array');
            Config::load($file, 'https://lr.example');
        } finally {
            unlink($file);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function outOfRange(): iterable
    {
        yield 'negative max file size' => [['max_file_size' => -1]];
        yield 'rate limit attempts zero' => [['rate_limit_attempts' => 0]];
        yield 'rate limit window zero' => [['rate_limit_window' => 0]];
    }

    /** @param array<string, mixed> $values */
    #[\PHPUnit\Framework\Attributes\DataProvider('outOfRange')]
    public function testOutOfRangeValues(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        Config::fromArray($values, 'https://x');
    }
}

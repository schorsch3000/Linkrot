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
        $config = Config::fromArray(['base_url' => 'https://linkrot.example']);

        self::assertSame(32 * 1024 * 1024, $config->chunkSize);
        self::assertSame('1w', $config->defaultRmAfter);
        self::assertSame('1y', $config->maxRmAfter);
        self::assertNull($config->defaultUses);
        self::assertSame(DownloadMode::Xaccel, $config->downloadMode);
    }

    public function testOverrides(): void
    {
        $config = Config::fromArray([
            'base_url' => 'http://localhost:8080',
            'download_mode' => 'php',
            'default_uses' => 3,
            'max_rm_after' => '1000y',
        ]);

        self::assertSame(DownloadMode::Php, $config->downloadMode);
        self::assertSame(3, $config->defaultUses);
        self::assertSame('1000y', $config->maxRmAfter);
    }

    /** @return iterable<string, array{string}> */
    public static function allowed(): iterable
    {
        yield 'https' => ['https://linkrot.example'];
        yield 'https with port and path' => ['https://linkrot.example:8443/lr'];
        yield 'localhost' => ['http://localhost'];
        yield 'localhost with port' => ['http://localhost:8080'];
        yield 'loopback' => ['http://127.0.0.1:8080'];
        yield 'loopback range' => ['http://127.1.2.3'];
        yield 'ipv6 loopback' => ['http://[::1]:8080'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('allowed')]
    public function testAllowedBaseUrls(string $url): void
    {
        self::assertSame($url, Config::fromArray(['base_url' => $url])->baseUrl);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'missing base_url' => [[]];
        yield 'bad base_url' => [['base_url' => 'ftp://x']];
        yield 'plain http' => [['base_url' => 'http://linkrot.example']];
        yield 'plain http on a lan address' => [['base_url' => 'http://192.168.1.10:8080']];
        yield 'plain http, localhost only in the userinfo' => [['base_url' => 'http://localhost@evil.example']];
        yield 'plain http, localhost as a prefix' => [['base_url' => 'http://localhost.evil.example']];
        yield 'unknown key' => [['base_url' => 'https://x', 'nope' => 1]];
        yield 'chunk too small' => [['base_url' => 'https://x', 'chunk_size' => 1024]];
        yield 'chunk too big' => [['base_url' => 'https://x', 'chunk_size' => 200_000_000]];
        yield 'bad mode' => [['base_url' => 'https://x', 'download_mode' => 'sendfile']];
        yield 'uses zero' => [['base_url' => 'https://x', 'default_uses' => 0]];
        yield 'wrong type' => [['base_url' => 'https://x', 'chunk_size' => '32']];
    }

    /** @param array<string, mixed> $values */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalid(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        Config::fromArray($values);
    }

    public function testLoadReadsAPhpFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'linkrot-config-') ?: self::fail('tempnam');
        file_put_contents($file, "<?php return ['base_url' => 'https://lr.example', 'default_uses' => 2];\n");
        try {
            $config = Config::load($file);

            self::assertSame('https://lr.example', $config->baseUrl);
            self::assertSame(2, $config->defaultUses);
        } finally {
            unlink($file);
        }
    }

    public function testLoadReportsAMissingFileAndAFileThatReturnsNoArray(): void
    {
        try {
            Config::load('/nonexistent/linkrot/config.php');
            self::fail('a missing file must be reported');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('file not found', $e->getMessage());
        }

        $file = tempnam(sys_get_temp_dir(), 'linkrot-config-') ?: self::fail('tempnam');
        file_put_contents($file, "<?php return 'oops';\n");
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('must return an array');
            Config::load($file);
        } finally {
            unlink($file);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function outOfRange(): iterable
    {
        yield 'negative max file size' => [['base_url' => 'https://x', 'max_file_size' => -1]];
        yield 'rate limit attempts zero' => [['base_url' => 'https://x', 'rate_limit_attempts' => 0]];
        yield 'rate limit window zero' => [['base_url' => 'https://x', 'rate_limit_window' => 0]];
    }

    /** @param array<string, mixed> $values */
    #[\PHPUnit\Framework\Attributes\DataProvider('outOfRange')]
    public function testOutOfRangeValues(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        Config::fromArray($values);
    }
}

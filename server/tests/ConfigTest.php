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

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'missing base_url' => [[]];
        yield 'bad base_url' => [['base_url' => 'ftp://x']];
        yield 'unknown key' => [['base_url' => 'http://x', 'nope' => 1]];
        yield 'chunk too small' => [['base_url' => 'http://x', 'chunk_size' => 1024]];
        yield 'chunk too big' => [['base_url' => 'http://x', 'chunk_size' => 200_000_000]];
        yield 'bad mode' => [['base_url' => 'http://x', 'download_mode' => 'sendfile']];
        yield 'uses zero' => [['base_url' => 'http://x', 'default_uses' => 0]];
        yield 'wrong type' => [['base_url' => 'http://x', 'chunk_size' => '32']];
    }

    /** @param array<string, mixed> $values */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalid(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        Config::fromArray($values);
    }
}

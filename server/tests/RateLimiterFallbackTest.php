<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\App;
use Linkrot\Auth\SqliteRateLimiter;
use Linkrot\Config;
use Linkrot\Database;
use Linkrot\Http\Request;
use PHPUnit\Framework\TestCase;

/** Without APCu (phpunit without apc.enable_cli, like shared hosting) the app counts failed logins in ratelimit.sqlite. */
final class RateLimiterFallbackTest extends TestCase
{
    public function testWithoutApcuTheFailuresAreCountedInADatabaseOfTheirOwn(): void
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            self::markTestSkipped('APCu is enabled; run phpunit without -d apc.enable_cli=1');
        }
        $dir = sys_get_temp_dir() . '/linkrot-fallback-' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            Database::openMigrated($dir . '/linkrot.sqlite');
            $app = new App(new Config(baseUrl: 'https://lr.example', rateLimitAttempts: 2), $dir, 'lr.example');
            $wrong = static fn(): int => $app->handle(new Request('GET', '/api/v1/files', ['authorization' => 'Bearer lr_wrong'], server: ['REMOTE_ADDR' => '192.0.2.1']))->status;

            self::assertSame([401, 401, 429], [$wrong(), $wrong(), $wrong()]);
            self::assertFileExists($dir . '/' . SqliteRateLimiter::FILE_NAME);
        } finally {
            array_map(unlink(...), glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }
}

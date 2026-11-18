<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Auth\ApcuRateLimiter;
use Linkrot\Auth\Authenticator;
use Linkrot\Auth\ClientKey;
use Linkrot\Auth\Guard;
use Linkrot\Auth\InMemoryRateLimiter;
use Linkrot\Auth\RateLimiter;
use Linkrot\Auth\SqliteRateLimiter;
use Linkrot\Auth\TokenStore;
use Linkrot\Database;
use Linkrot\Http\Request;
use PDO;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private int $now = 1000;

    private function memory(int $attempts = 3, int $window = 900): InMemoryRateLimiter
    {
        return new InMemoryRateLimiter($attempts, $window, fn(): int => $this->now);
    }

    public function testBlocksAfterAttemptsAndExpires(): void
    {
        $limiter = $this->memory();

        for ($i = 0; $i < 3; ++$i) {
            self::assertNull($limiter->blockedFor('192.0.2.1'));
            $limiter->recordFailure('192.0.2.1');
        }
        self::assertSame(900, $limiter->blockedFor('192.0.2.1'));
        self::assertNull($limiter->blockedFor('192.0.2.2'));

        $this->now += 400;
        self::assertSame(500, $limiter->blockedFor('192.0.2.1'));

        $this->now += 500;
        self::assertNull($limiter->blockedFor('192.0.2.1'));
    }

    public function testClientKeyGroupsIpv6By64(): void
    {
        self::assertSame(ClientKey::for('2001:db8:1:2::1'), ClientKey::for('2001:db8:1:2:ffff:eeee:dddd:cccc'));
        self::assertNotSame(ClientKey::for('2001:db8:1:2::1'), ClientKey::for('2001:db8:1:3::1'));
        self::assertSame(ClientKey::for('192.0.2.7'), ClientKey::for('::ffff:192.0.2.7'));
        self::assertNotSame(ClientKey::for('192.0.2.7'), ClientKey::for('192.0.2.8'));
        self::assertSame(64, strlen(ClientKey::for('garbage')));
    }

    public function testGuard(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        Database::migrate($pdo);
        $store = new TokenStore($pdo);
        $token = $store->create('laptop');
        $guard = new Guard(new Authenticator($store), $this->memory(2));
        $server = ['REMOTE_ADDR' => '192.0.2.1'];
        $good = new Request('GET', '/', ['authorization' => "Bearer $token"], server: $server);
        $bad = new Request('GET', '/', ['authorization' => 'Bearer lr_nope'], server: $server);

        self::assertNull($guard->check($good));
        self::assertSame(401, $guard->check($bad)?->status);
        self::assertNull($guard->check($good));
        self::assertSame(401, $guard->check($bad)?->status);

        $blocked = $guard->check($good);
        self::assertSame(429, $blocked?->status);
        self::assertSame('900', $blocked->headers['Retry-After'] ?? null);
        self::assertStringContainsString('rate_limited', $blocked->body);
    }

    public function testApcu(): void
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            self::markTestSkipped('APCu not enabled in the CLI (run with -d apc.enable_cli=1)');
        }
        apcu_clear_cache();
        $limiter = new ApcuRateLimiter(2, 60);
        self::assertInstanceOf(RateLimiter::class, $limiter);

        self::assertNull($limiter->blockedFor('192.0.2.9'));
        $limiter->recordFailure('192.0.2.9');
        self::assertNull($limiter->blockedFor('192.0.2.9'));
        $limiter->recordFailure('192.0.2.9');
        $blocked = $limiter->blockedFor('192.0.2.9');
        self::assertNotNull($blocked);
        self::assertLessThanOrEqual(60, $blocked);
        self::assertNull($limiter->blockedFor('192.0.2.10'));
    }

    public function testApcuTenantsDoNotShareAttempts(): void
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            self::markTestSkipped('APCu not enabled in the CLI (run with -d apc.enable_cli=1)');
        }
        apcu_clear_cache();
        $one = new ApcuRateLimiter(1, 60, 'one.example');
        $two = new ApcuRateLimiter(1, 60, 'two.example');

        $one->recordFailure('192.0.2.9');

        self::assertNotNull($one->blockedFor('192.0.2.9'));
        self::assertNull($two->blockedFor('192.0.2.9'));
    }

    private function sqliteFile(): string
    {
        $path = sys_get_temp_dir() . '/linkrot-ratelimit-' . bin2hex(random_bytes(6)) . '.sqlite';
        register_shutdown_function(static fn(): bool => @unlink($path));

        return $path;
    }

    public function testSqliteBlocksAfterAttemptsAndExpiresLikeTheOthers(): void
    {
        $limiter = new SqliteRateLimiter($this->sqliteFile(), 3, 900, 'lr.example', fn(): int => $this->now);

        for ($i = 0; $i < 3; ++$i) {
            self::assertNull($limiter->blockedFor('192.0.2.1'));
            $limiter->recordFailure('192.0.2.1');
        }
        self::assertSame(900, $limiter->blockedFor('192.0.2.1'));
        self::assertNull($limiter->blockedFor('192.0.2.2'));

        $this->now += 400;
        self::assertSame(500, $limiter->blockedFor('192.0.2.1'));

        $this->now += 500;
        self::assertNull($limiter->blockedFor('192.0.2.1'));
        $limiter->recordFailure('192.0.2.1');
        self::assertNull($limiter->blockedFor('192.0.2.1'), 'a new window starts from zero');
    }

    public function testSqliteTenantsDoNotShareAttemptsAndOldWindowsAreRemoved(): void
    {
        $path = $this->sqliteFile();
        $one = new SqliteRateLimiter($path, 1, 60, 'one.example', fn(): int => $this->now);
        $two = new SqliteRateLimiter($path, 1, 60, 'two.example', fn(): int => $this->now);

        $one->recordFailure('192.0.2.9');
        self::assertNotNull($one->blockedFor('192.0.2.9'));
        self::assertNull($two->blockedFor('192.0.2.9'));

        $this->now += 61;
        $two->recordFailure('192.0.2.10');
        $rows = Database::query(new PDO('sqlite:' . $path), 'SELECT count(*) FROM windows')->fetchColumn();
        self::assertSame(1, $rows, 'the window that is over was removed by the next failure');
    }

    public function testSqliteFailsOpenWhenItsDatabaseIsUnusable(): void
    {
        $limiter = new SqliteRateLimiter('/nonexistent/folder/ratelimit.sqlite', 1, 60, '', fn(): int => $this->now);
        $log = ini_set('error_log', '/dev/null');
        try {
            $limiter->recordFailure('192.0.2.1');
            $limiter->recordFailure('192.0.2.1');
            self::assertNull($limiter->blockedFor('192.0.2.1'));
        } finally {
            ini_set('error_log', (string) $log);
        }
    }

    public function testSqliteDoesNotWaitForALockOfItsDatabase(): void
    {
        $path = $this->sqliteFile();
        $limiter = new SqliteRateLimiter($path, 1, 60, '', fn(): int => $this->now);
        $limiter->recordFailure('192.0.2.1');
        $holder = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $holder->exec('BEGIN EXCLUSIVE');
        $log = ini_set('error_log', '/dev/null');
        try {
            $started = microtime(true);
            $limiter->recordFailure('192.0.2.2');
            self::assertLessThan(2.0, microtime(true) - $started);
        } finally {
            ini_set('error_log', (string) $log);
            $holder->exec('ROLLBACK');
        }
    }
}

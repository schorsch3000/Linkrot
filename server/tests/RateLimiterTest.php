<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Auth\ApcuRateLimiter;
use Linkrot\Auth\Authenticator;
use Linkrot\Auth\ClientKey;
use Linkrot\Auth\Guard;
use Linkrot\Auth\InMemoryRateLimiter;
use Linkrot\Auth\RateLimiter;
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
}

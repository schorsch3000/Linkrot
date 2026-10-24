<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Auth\TokenStore;
use Linkrot\Cli\AuthCommand;
use Linkrot\Database;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthCommandTest extends TestCase
{
    private TokenStore $store;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        Database::migrate($pdo);
        $this->store = new TokenStore($pdo);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function exec(array $args): array
    {
        $out = fopen('php://memory', 'w+') ?: self::fail('memory stream');
        $err = fopen('php://memory', 'w+') ?: self::fail('memory stream');
        $code = new AuthCommand($this->store, $out, $err)->run($args);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testGetPrintsTokenOnly(): void
    {
        [$code, $out, $err] = $this->exec(['get', 'laptop']);

        self::assertSame(0, $code);
        self::assertSame('', $err);
        self::assertSame('laptop', $this->store->authenticate(trim($out)));
        self::assertStringEndsWith("\n", $out);
    }

    public function testGetDuplicateFails(): void
    {
        $this->exec(['get', 'laptop']);
        [$code, $out, $err] = $this->exec(['get', 'laptop']);

        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('already exists', $err);
    }

    public function testDelByName(): void
    {
        [, $token] = $this->exec(['get', 'laptop']);

        self::assertSame(0, $this->exec(['del', 'laptop'])[0]);
        self::assertNull($this->store->authenticate(trim($token)));
        self::assertSame(1, $this->exec(['del', 'laptop'])[0]);
    }

    public function testLsShowsNameAndLastUsedOnly(): void
    {
        [, $token] = $this->exec(['get', 'laptop']);
        $this->exec(['get', 'phone']);
        $this->store->authenticate(trim($token), 86400);

        [$code, $out] = $this->exec(['ls']);

        self::assertSame(0, $code);
        self::assertSame("laptop\t1970-01-02 00:00:00 UTC\nphone\tnever\n", $out);
        self::assertStringNotContainsString('lr_', $out);
    }

    public function testUsage(): void
    {
        foreach ([[], ['get'], ['del'], ['ls', 'x'], ['nope']] as $args) {
            [$code, , $err] = $this->exec($args);
            self::assertSame(2, $code);
            self::assertStringContainsString('usage:', $err);
        }
    }
}

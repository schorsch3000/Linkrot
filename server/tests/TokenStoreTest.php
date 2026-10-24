<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use InvalidArgumentException;
use Linkrot\Auth\Authenticator;
use Linkrot\Auth\TokenStore;
use Linkrot\Database;
use Linkrot\Http\Request;
use PDO;
use PHPUnit\Framework\TestCase;

final class TokenStoreTest extends TestCase
{
    private PDO $pdo;
    private TokenStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        Database::migrate($this->pdo);
        $this->store = new TokenStore($this->pdo);
    }

    public function testCreateAndAuthenticate(): void
    {
        $token = $this->store->create('laptop', 1000);

        self::assertMatchesRegularExpression('~^lr_[A-Za-z0-9_-]{43}$~', $token);
        self::assertSame('laptop', $this->store->authenticate($token, 1000));
        self::assertNull($this->store->authenticate($token . 'x', 1000));
        self::assertNull($this->store->authenticate('lr_unknown', 1000));
        self::assertNull($this->store->authenticate('', 1000));
    }

    public function testOnlyHashIsStored(): void
    {
        $token = $this->store->create('laptop');

        $stored = Database::query($this->pdo, 'SELECT hash FROM tokens')->fetchColumn();
        self::assertSame(hash('sha256', $token), $stored);
        self::assertStringNotContainsString($token, (string) json_encode(Database::query($this->pdo, 'SELECT * FROM tokens')->fetchAll()));
    }

    public function testTokensAreUnique(): void
    {
        self::assertNotSame($this->store->create('a'), $this->store->create('b'));
    }

    public function testDuplicateNameRejected(): void
    {
        $this->store->create('laptop');

        $this->expectException(InvalidArgumentException::class);
        $this->store->create('laptop');
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Laptop'];
        yield 'digit' => ['laptop2'];
        yield 'space' => ['my laptop'];
        yield 'underscore' => ['my_laptop'];
        yield 'too long' => [str_repeat('a', 33)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badNames')]
    public function testInvalidName(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->store->create($name);
    }

    public function testDeleteRevokes(): void
    {
        $token = $this->store->create('laptop');

        self::assertTrue($this->store->delete('laptop'));
        self::assertNull($this->store->authenticate($token));
        self::assertFalse($this->store->delete('laptop'));
    }

    public function testLastUsedIsThrottled(): void
    {
        $token = $this->store->create('laptop', 1000);
        self::assertNull($this->lastUsed());

        $this->store->authenticate($token, 2000);
        self::assertSame(2000, $this->lastUsed());

        $this->store->authenticate($token, 2000 + TokenStore::TOUCH_INTERVAL - 1);
        self::assertSame(2000, $this->lastUsed());

        $this->store->authenticate($token, 2000 + TokenStore::TOUCH_INTERVAL);
        self::assertSame(2000 + TokenStore::TOUCH_INTERVAL, $this->lastUsed());
    }

    /** @phpstan-impure */
    private function lastUsed(): ?int
    {
        return $this->store->list()[0]['last_used_at'];
    }

    public function testAuthenticatorReadsBearerHeader(): void
    {
        $token = $this->store->create('laptop');
        $auth = new Authenticator($this->store);

        self::assertSame('laptop', $auth->authenticate(new Request('GET', '/', ['authorization' => "Bearer $token"])));
        self::assertSame('laptop', $auth->authenticate(new Request('GET', '/', ['authorization' => "bearer $token"])));
        self::assertNull($auth->authenticate(new Request('GET', '/', ['authorization' => $token])));
        self::assertNull($auth->authenticate(new Request('GET', '/', ['authorization' => 'Basic abc'])));
        self::assertNull($auth->authenticate(new Request('GET', '/')));
    }
}

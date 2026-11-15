<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Auth\ChallengeStore;
use Linkrot\Auth\SessionStore;
use Linkrot\Auth\TokenStore;
use Linkrot\Config;
use Linkrot\Tests\Support\AppTestCase;

final class SelfServiceTokensTest extends AppTestCase
{
    protected function config(): Config
    {
        return new Config(baseUrl: 'https://lr.example', email: 'owner@example.com');
    }

    /** @return array<string, mixed> */
    private function json(\Linkrot\Http\Response $response): array
    {
        $data = json_decode($response->body, true);
        self::assertIsArray($data);

        return $data;
    }

    private function start(string $email = 'owner@example.com'): string
    {
        $response = $this->request('POST', '/api/v1/auth/start', json: ['email' => $email], auth: false);
        self::assertSame(202, $response->status);
        $challenge = $this->json($response)['challenge'];
        self::assertIsString($challenge);

        return $challenge;
    }

    private function login(): string
    {
        $challenge = $this->start();
        $response = $this->request('POST', '/api/v1/auth/verify', json: ['challenge' => $challenge, 'code' => $this->mailer->lastCode()], auth: false);
        self::assertSame(200, $response->status);
        $session = $this->json($response)['session'];
        self::assertIsString($session);

        return $session;
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function manage(string $session, string $method, string $path, ?array $json = null): \Linkrot\Http\Response
    {
        return $this->request($method, $path, ['authorization' => 'Bearer ' . $session], $json, auth: false);
    }

    public function testStartMailsACodeToTheOwner(): void
    {
        $this->start('Owner@Example.com ');
        self::assertCount(1, $this->mailer->sent);
        self::assertSame('owner@example.com', $this->mailer->sent[0]['to']);
        self::assertMatchesRegularExpression('~^[A-Z2-9]{4}-[A-Z2-9]{4}$~', $this->mailer->lastCode());
    }

    public function testStartAnswersTheSameForAnUnknownAddressAndSendsNothing(): void
    {
        $known = $this->request('POST', '/api/v1/auth/start', json: ['email' => 'owner@example.com'], auth: false);
        $this->now += 100;
        $unknown = $this->request('POST', '/api/v1/auth/start', json: ['email' => 'stranger@example.com'], auth: false);
        self::assertSame($known->status, $unknown->status);
        self::assertSame(array_keys($this->json($known)), array_keys($this->json($unknown)));
        self::assertSame($this->json($known)['message'], $this->json($unknown)['message']);
        self::assertSame(strlen((string) $this->json($known)['challenge']), strlen((string) $this->json($unknown)['challenge']));
        self::assertCount(1, $this->mailer->sent);
    }

    public function testStartIsTheSameWhenTheHostHasNoAddress(): void
    {
        $this->app = $this->appWith(new Config(baseUrl: 'https://lr.example'));
        $response = $this->request('POST', '/api/v1/auth/start', json: ['email' => 'owner@example.com'], auth: false);
        self::assertSame(202, $response->status);
        self::assertSame([], $this->mailer->sent);
    }

    public function testStartTakesTheConfiguredTimeWhetherOrNotMailIsSent(): void
    {
        $app = $this->appWith($this->config(), 0.3);
        foreach (['owner@example.com', 'stranger@example.com'] as $email) {
            $began = hrtime(true);
            $app->handle(new \Linkrot\Http\Request('POST', '/api/v1/auth/start', [], [], ['REMOTE_ADDR' => '192.0.2.9'], $this->body(['email' => $email])));
            $took = (hrtime(true) - $began) / 1e9;
            self::assertGreaterThanOrEqual(0.29, $took, $email);
            self::assertLessThan(0.6, $took, $email);
            $this->now += 100;
        }
    }

    public function testAMailThatCannotBeSentChangesNothingForTheClient(): void
    {
        $this->mailer->fail = true;
        self::assertNotSame('', $this->start());
    }

    public function testMailsAreSpacedAndCappedWithoutTheClientNoticing(): void
    {
        $this->start();
        $this->start();
        self::assertCount(1, $this->mailer->sent, 'second request within 30 s');
        for ($i = 0; $i < 7; ++$i) {
            $this->now += ChallengeStore::MIN_SPACING;
            $this->start();
        }
        self::assertCount(ChallengeStore::MAX_PER_HOUR, $this->mailer->sent);
        $this->now += 3600;
        $this->start();
        self::assertCount(ChallengeStore::MAX_PER_HOUR + 1, $this->mailer->sent);
    }

    public function testStartIsRateLimitedPerClient(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->start('nobody@example.com');
        }
        $response = $this->request('POST', '/api/v1/auth/start', json: ['email' => 'nobody@example.com'], auth: false);
        self::assertSame(429, $response->status);
        self::assertArrayHasKey('Retry-After', $response->headers);
    }

    public function testStartNeedsAnEmailString(): void
    {
        self::assertSame(400, $this->request('POST', '/api/v1/auth/start', json: ['mail' => 'x'], auth: false)->status);
        self::assertSame(400, $this->request('POST', '/api/v1/auth/start', raw: 'nope', auth: false)->status);
    }

    public function testTheCodeBuysASessionOnce(): void
    {
        $challenge = $this->start();
        $code = $this->mailer->lastCode();
        $ok = $this->request('POST', '/api/v1/auth/verify', json: ['challenge' => $challenge, 'code' => strtolower(str_replace('-', ' ', $code))], auth: false);
        self::assertSame(200, $ok->status);
        self::assertSame(SessionStore::LIFETIME, $this->json($ok)['expires_in']);
        $again = $this->request('POST', '/api/v1/auth/verify', json: ['challenge' => $challenge, 'code' => $code], auth: false);
        self::assertSame(401, $again->status);
    }

    public function testWrongCodeUnknownChallengeAndExpiredCodeAnswerAlike(): void
    {
        $challenge = $this->start();
        $code = $this->mailer->lastCode();
        $wrong = $this->request('POST', '/api/v1/auth/verify', json: ['challenge' => $challenge, 'code' => 'AAAA-AAAA'], auth: false);
        $unknown = $this->request('POST', '/api/v1/auth/verify', json: ['challenge' => ChallengeStore::newId(), 'code' => $code], auth: false);
        $this->now += ChallengeStore::LIFETIME;
        $expired = $this->request('POST', '/api/v1/auth/verify', json: ['challenge' => $challenge, 'code' => $code], auth: false);
        foreach ([$wrong, $unknown, $expired] as $response) {
            self::assertSame(401, $response->status);
            self::assertSame('invalid_code', $this->json($response)['error']['code'] ?? null);
        }
        self::assertSame($wrong->body, $unknown->body);
    }

    public function testACodeOnlyWorksWithItsOwnChallenge(): void
    {
        $this->start();
        $code = $this->mailer->lastCode();
        $this->now += ChallengeStore::MIN_SPACING;
        $other = $this->start();
        $response = $this->request('POST', '/api/v1/auth/verify', json: ['challenge' => $other, 'code' => $code], auth: false);
        self::assertSame(401, $response->status);
    }

    public function testAChallengeDiesAfterFiveWrongGuesses(): void
    {
        $challenge = $this->start();
        $code = $this->mailer->lastCode();
        for ($i = 0; $i < ChallengeStore::MAX_ATTEMPTS; ++$i) {
            $this->request('POST', '/api/v1/auth/verify', json: ['challenge' => $challenge, 'code' => 'AAAA-AAAA'], auth: false);
        }
        $response = $this->request('POST', '/api/v1/auth/verify', json: ['challenge' => $challenge, 'code' => $code], auth: false);
        self::assertSame(401, $response->status);
    }

    public function testVerifyIsRateLimitedPerClient(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->request('POST', '/api/v1/auth/verify', json: ['challenge' => ChallengeStore::newId(), 'code' => 'AAAA-AAAA'], auth: false);
        }
        self::assertSame(429, $this->request('POST', '/api/v1/auth/verify', json: ['challenge' => 'x', 'code' => 'y'], auth: false)->status);
    }

    public function testCodesAreStoredHashed(): void
    {
        $this->start();
        $code = str_replace('-', '', $this->mailer->lastCode());
        $rows = \Linkrot\Database::query($this->app->db(), 'SELECT id, code_hash FROM challenges')->fetchAll();
        self::assertCount(1, $rows);
        self::assertStringNotContainsString($code, implode(' ', (array) $rows[0]));
    }

    public function testManageTokensWithASession(): void
    {
        $session = $this->login();
        $created = $this->manage($session, 'POST', '/api/v1/tokens', ['name' => 'laptop']);
        self::assertSame(201, $created->status);
        $token = $this->json($created)['token'];
        self::assertIsString($token);
        self::assertSame('laptop', new TokenStore($this->app->db())->authenticate($token));

        $list = $this->json($this->manage($session, 'GET', '/api/v1/tokens'));
        self::assertSame(['laptop', 'test'], array_column($list['tokens'] ?? [], 'name'));

        self::assertSame(204, $this->manage($session, 'DELETE', '/api/v1/tokens/laptop')->status);
        self::assertNull(new TokenStore($this->app->db())->authenticate($token));
        self::assertSame(404, $this->manage($session, 'DELETE', '/api/v1/tokens/laptop')->status);
    }

    public function testEveryChangeIsMailedToTheOwner(): void
    {
        $session = $this->login();
        $this->manage($session, 'POST', '/api/v1/tokens', ['name' => 'laptop']);
        $this->manage($session, 'DELETE', '/api/v1/tokens/laptop');
        self::assertCount(3, $this->mailer->sent);
        self::assertStringContainsString("token 'laptop' was created", $this->mailer->sent[1]['subject']);
        self::assertStringContainsString("token 'laptop' was revoked", $this->mailer->sent[2]['subject']);
        self::assertSame('owner@example.com', $this->mailer->sent[2]['to']);
    }

    public function testNoticeMailFailureDoesNotFailTheChange(): void
    {
        $session = $this->login();
        $this->mailer->fail = true;
        self::assertSame(201, $this->manage($session, 'POST', '/api/v1/tokens', ['name' => 'laptop'])->status);
    }

    public function testCreateRefusesBadAndTakenNamesAndTooManyTokens(): void
    {
        $session = $this->login();
        self::assertSame(400, $this->manage($session, 'POST', '/api/v1/tokens', ['name' => 'Not Valid'])->status);
        self::assertSame(400, $this->manage($session, 'POST', '/api/v1/tokens', [])->status);
        self::assertSame(409, $this->manage($session, 'POST', '/api/v1/tokens', ['name' => 'test'])->status);
        $tokens = new TokenStore($this->app->db());
        for ($i = $tokens->count(); $i < \Linkrot\Api\TokensController::MAX_TOKENS; ++$i) {
            $tokens->create('t' . strtr((string) $i, '0123456789', 'abcdefghij'));
        }
        $full = $this->manage($session, 'POST', '/api/v1/tokens', ['name' => 'one-more']);
        self::assertSame(409, $full->status);
        self::assertSame('limit_reached', $this->json($full)['error']['code'] ?? null);
    }

    public function testASessionEndsAfterTenMinutesNoMatterWhat(): void
    {
        $session = $this->login();
        $this->now += SessionStore::LIFETIME - 1;
        self::assertSame(200, $this->manage($session, 'GET', '/api/v1/tokens')->status);
        $this->now += 1;
        self::assertSame(401, $this->manage($session, 'GET', '/api/v1/tokens')->status);
    }

    public function testAnApiTokenCannotManageTokensAndASessionCannotTouchFiles(): void
    {
        self::assertSame(401, $this->request('GET', '/api/v1/tokens')->status);
        self::assertSame(401, $this->request('POST', '/api/v1/tokens', json: ['name' => 'x'])->status);
        $session = $this->login();
        self::assertSame(401, $this->manage($session, 'GET', '/api/v1/files')->status);
        self::assertSame(401, $this->manage($session, 'GET', '/api/v1/health')->status);
    }

    public function testNoSessionNoManagement(): void
    {
        self::assertSame(401, $this->request('GET', '/api/v1/tokens', auth: false)->status);
        self::assertSame(401, $this->manage('lm_nonsense', 'GET', '/api/v1/tokens')->status);
    }

    private function appWith(Config $config, float $delay = 0.0): \Linkrot\App
    {
        return new \Linkrot\App(
            $config,
            $this->dir,
            'lr.example',
            new \Linkrot\Auth\InMemoryRateLimiter(10, 900, fn(): int => $this->now),
            fn(): int => $this->now,
            $this->mailer,
            new \Linkrot\Auth\InMemoryRateLimiter(10, 900, fn(): int => $this->now),
            $delay,
        );
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return resource
     */
    private function body(array $json): mixed
    {
        $stream = fopen('php://memory', 'w+') ?: self::fail('stream');
        fwrite($stream, json_encode($json, JSON_THROW_ON_ERROR));
        rewind($stream);

        return $stream;
    }
}

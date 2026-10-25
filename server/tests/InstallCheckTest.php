<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Closure;
use Linkrot\Auth\TokenStore;
use Linkrot\Cli\InstallCheck;
use Linkrot\Database;
use Linkrot\Http\Request;
use Linkrot\Tests\Support\AppTestCase;

final class InstallCheckTest extends AppTestCase
{
    /** @var list<string> */
    private array $lines = [];

    /**
     * Talks to the app in process and plays nginx: X-Accel-Redirect is answered with the file, and X-Rot only gets
     * through if the nginx config says so.
     *
     * @param (Closure(string, string): ?array{int, array<string, string>, string})|null $override answers instead of the app
     *
     * @return Closure(string, string, array<string, string>, string): array{int, array<string, string>, string}
     */
    private function nginx(bool $forwardsXRot = true, ?Closure $override = null): Closure
    {
        return function (string $method, string $path, array $headers, string $body) use ($forwardsXRot, $override): array {
            if ($override instanceof \Closure && ($answer = $override($method, $path)) !== null) {
                return $answer;
            }
            $stream = fopen('php://memory', 'w+') ?: self::fail('stream');
            fwrite($stream, $body);
            rewind($stream);
            $headers += ['content-length' => (string) strlen($body)];
            $response = $this->app->handle(new Request($method, $path, $headers, [], ['REMOTE_ADDR' => '192.0.2.1'], $stream));
            $out = array_change_key_case($response->headers, CASE_LOWER);
            $content = $response->body;
            if (isset($out['x-accel-redirect'])) {
                $content = (string) file_get_contents($this->dir . '/files/' . basename($out['x-accel-redirect']));
                unset($out['x-accel-redirect']);
                if (!$forwardsXRot) {
                    unset($out['x-rot']);
                }
            }

            return [$response->status, $out, $content];
        };
    }

    private function check(?Closure $http = null): bool
    {
        $check = new InstallCheck(new TokenStore($this->app->db()), $http ?? $this->nginx(), function (string $line): void {
            $this->lines[] = $line;
        });

        return $check->run();
    }

    private function files(): int
    {
        return (int) Database::query($this->app->db(), 'SELECT count(*) FROM files')->fetchColumn();
    }

    /** @return list<string> */
    private function tokenNames(): array
    {
        return array_column(new TokenStore($this->app->db())->list(), 'name');
    }

    public function testPassesAndLeavesNothingBehind(): void
    {
        self::assertTrue($this->check(), implode("\n", $this->lines));

        self::assertContains('ok: health cleanup: warn, the cleanup has not run yet', $this->lines);
        self::assertSame(1, count(array_filter($this->lines, static fn(string $l): bool => str_starts_with($l, 'ok: download headers: ok, X-Rot: '))));
        self::assertSame(['test'], $this->tokenNames(), 'the test token is gone');
        self::assertSame(0, $this->files(), 'the check file is gone');
    }

    public function testFailsWhenNginxDropsXRotAndStillCleansUp(): void
    {
        self::assertFalse($this->check($this->nginx(forwardsXRot: false)));

        $failure = implode("\n", array_filter($this->lines, static fn(string $l): bool => str_starts_with($l, 'FAILED')));
        self::assertStringContainsString('X-Rot header is missing', $failure);
        self::assertStringContainsString('add_header X-Rot $upstream_http_x_rot always;', $failure);
        self::assertSame(['test'], $this->tokenNames());
        self::assertSame(0, $this->files());
    }

    public function testReplacesALeftoverTestToken(): void
    {
        $tokens = new TokenStore($this->app->db());
        $tokens->create(InstallCheck::TOKEN_NAME);

        self::assertTrue($this->check(), implode("\n", $this->lines));
        self::assertSame(['test'], $this->tokenNames());
    }

    public function testCleansUpWhenTheDownloadCannotBeReached(): void
    {
        $http = $this->nginx(override: static fn(string $method, string $path): ?array => str_starts_with($path, '/dl/') ? throw new \RuntimeException('cannot reach https://lr.example' . $path . ': timed out') : null);

        self::assertFalse($this->check($http));
        self::assertStringContainsString('FAILED: cannot reach https://lr.example/dl/', implode("\n", $this->lines));
        self::assertSame(['test'], $this->tokenNames());
        self::assertSame(0, $this->files());
    }

    public function testAnErrorInTheHealthReportFailsBeforeAnythingIsUploaded(): void
    {
        $http = $this->nginx(override: static fn(string $method, string $path): ?array => $path === '/api/v1/health'
            ? [200, [], '{"status":"error","checks":[{"name":"storage","level":"error","message":"the data directory is missing or not writable"}]}']
            : null);

        self::assertFalse($this->check($http));
        self::assertContains('FAILED: the health endpoint reports an error', $this->lines);
        self::assertSame(0, $this->files());
        self::assertSame(['test'], $this->tokenNames());
    }

    public function testAHealthEndpointThatAnswersWithAnErrorStatusFails(): void
    {
        $http = $this->nginx(override: static fn(string $method, string $path): ?array => $path === '/api/v1/health' ? [503, [], '{"error":{"code":"schema_outdated","message":"run app/install.sh"}}'] : null);

        self::assertFalse($this->check($http));
        self::assertStringContainsString('health endpoint answered HTTP 503', implode("\n", $this->lines));
    }
}

<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Tests\Support\AppTestCase;

final class HealthTest extends AppTestCase
{
    /**
     * @phpstan-impure
     *
     * @return array<string, array{level: string, message: string}>
     */
    private function checks(): array
    {
        [$status, $body] = $this->call('GET', '/api/v1/health');
        self::assertSame(200, $status);
        $checks = [];
        foreach ($body['checks'] as $check) {
            $checks[$check['name']] = $check;
        }
        $this->status = $body['status'];

        return $checks;
    }

    private string $status = '';

    public function testRequiresAToken(): void
    {
        self::assertSame(401, $this->request('GET', '/api/v1/health', auth: false)->status);
    }

    public function testFreshServerIsReadyAndOnlyWarnsThatCleanupHasNotRun(): void
    {
        $checks = $this->checks();

        self::assertSame(['database', 'storage', 'disk', 'cleanup'], array_keys($checks));
        self::assertSame('ok', $checks['database']['level']);
        self::assertSame('ok', $checks['storage']['level']);
        self::assertSame('warn', $checks['cleanup']['level']);
        self::assertStringContainsString('not run yet', $checks['cleanup']['message']);
        self::assertSame('warn', $this->status);
    }

    public function testAfterACleanupEverythingIsOk(): void
    {
        $this->call('POST', '/api/v1/cleanup');
        $checks = $this->checks();

        self::assertSame('ok', $checks['cleanup']['level'], $checks['cleanup']['message']);
        self::assertSame('ok', $this->status);
    }

    public function testStaleCleanupWarns(): void
    {
        $this->call('POST', '/api/v1/cleanup');
        $this->now += 16 * 3600 + 1; // more than twice the default interval of 8h
        $checks = $this->checks();

        self::assertSame('warn', $checks['cleanup']['level']);
        self::assertStringContainsString('last successful cleanup', $checks['cleanup']['message']);
        self::assertStringContainsString('every 8h', $checks['cleanup']['message']);
    }

    public function testACleanupThatCannotRemoveAFileIsAnErrorUntilTheNextCleanRun(): void
    {
        $this->call('POST', '/api/v1/cleanup');
        mkdir($this->dir . '/files', 0o755);
        $stray = $this->dir . '/files/' . \Linkrot\FileId::generate();
        file_put_contents($stray, 'x');
        touch($stray, 1);
        chmod($this->dir . '/files', 0o555);
        try {
            $this->now += 10;
            $this->call('POST', '/api/v1/cleanup');
            $checks = $this->checks();

            self::assertSame('warn', $checks['cleanup']['level']);
            self::assertStringContainsString('could not be removed', $checks['cleanup']['message']);
            self::assertSame('error', $checks['storage']['level'], 'a read-only files directory is an error');
            self::assertSame('error', $this->status);
        } finally {
            chmod($this->dir . '/files', 0o755);
        }

        $this->now += 10;
        $this->call('POST', '/api/v1/cleanup');
        $checks = $this->checks();
        self::assertSame('ok', $checks['cleanup']['level'], $checks['cleanup']['message']);
        self::assertFileDoesNotExist($stray);
    }

    public function testUnwritableDataDirectoryIsAnError(): void
    {
        chmod($this->dir, 0o555);
        try {
            $checks = $this->checks();

            self::assertSame('error', $checks['storage']['level']);
            self::assertSame('error', $this->status);
        } finally {
            chmod($this->dir, 0o755);
        }
    }

    public function testDiskCheckReportsFreeSpace(): void
    {
        $checks = $this->checks();

        self::assertContains($checks['disk']['level'], ['ok', 'warn']);
        self::assertMatchesRegularExpression('~free of~', $checks['disk']['message']);
    }
}

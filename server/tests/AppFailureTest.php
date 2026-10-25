<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\App;
use Linkrot\Config;
use Linkrot\Http\Request;
use PHPUnit\Framework\TestCase;

final class AppFailureTest extends TestCase
{
    public function testAnUnexpectedFailureIsAnInternalErrorWithoutDetails(): void
    {
        $dir = sys_get_temp_dir() . '/linkrot-broken-' . bin2hex(random_bytes(6));
        file_put_contents($dir, 'a file where the data directory should be');
        try {
            $app = new App(new Config(baseUrl: 'https://lr.example'), $dir);
            $request = new Request('GET', '/dl/abcd7KfRtm4pWxnZ', [], [], ['REMOTE_ADDR' => '192.0.2.1'], fopen('php://memory', 'r') ?: self::fail('stream'));

            $response = $app->handle($request);

            self::assertSame(500, $response->status);
            self::assertSame('{"error":{"code":"internal","message":"internal error"}}', $response->body);
        } finally {
            unlink($dir);
        }
    }

    public function testCleanupIfDueNeverThrows(): void
    {
        $dir = sys_get_temp_dir() . '/linkrot-broken-' . bin2hex(random_bytes(6));
        file_put_contents($dir, 'a file where the data directory should be');
        try {
            new App(new Config(baseUrl: 'https://lr.example'), $dir)->cleanupIfDue();
            $this->addToAssertionCount(1);
        } finally {
            unlink($dir);
        }
    }
}

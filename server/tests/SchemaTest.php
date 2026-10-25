<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\App;
use Linkrot\Config;
use Linkrot\Database;
use Linkrot\Http\Request;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/linkrot-schema-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testServerAnswersClearlyUntilInstallShHasMigrated(): void
    {
        $app = new App(new Config(baseUrl: 'https://lr.example'), $this->dir);
        $request = static fn(string $path): Request => new Request('GET', $path, [], [], ['REMOTE_ADDR' => '192.0.2.1'], fopen('php://memory', 'r') ?: throw new \RuntimeException('stream'));

        foreach (['/api/v1/files', '/api/v1/health', '/file/abcd7KfRtm4pWxnZ/a.txt', '/dl/abcd7KfRtm4pWxnZ'] as $path) {
            $response = $app->handle($request($path));
            self::assertSame(503, $response->status, $path);
            self::assertStringContainsString('"schema_outdated"', $response->body);
            self::assertStringContainsString('run app/install.sh', $response->body);
        }

        Database::openMigrated($this->dir . '/linkrot.sqlite');
        self::assertSame(404, new App(new Config(baseUrl: 'https://lr.example'), $this->dir)->handle($request('/dl/abcd7KfRtm4pWxnZ'))->status);
    }
}

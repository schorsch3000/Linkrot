<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Setup\Setup;
use Linkrot\Setup\SetupCode;
use Linkrot\Setup\SetupEnvironment;
use Linkrot\Tenants;
use PHPUnit\Framework\TestCase;

final class SetupTest extends TestCase
{
    private string $base;
    private string $root;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/linkrot-setup-' . bin2hex(random_bytes(6));
        $this->root = $this->base . '/public_html';
        mkdir($this->root, 0o755, true);
        // the code of the archive, as setup.php finds it
        symlink(__DIR__ . '/../app', $this->root . '/linkrot-app');
    }

    protected function tearDown(): void
    {
        @chmod($this->base, 0o755);
        $remove = static function (string $path) use (&$remove): void {
            if (is_link($path) || is_file($path)) {
                unlink($path);

                return;
            }
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $remove("$path/$entry");
                }
            }
            rmdir($path);
        };
        $remove($this->base);
    }

    private function wizard(): Setup
    {
        return new Setup(new SetupEnvironment($this->root, $this->root), fn(): int => $this->now);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    private function send(string $method, array $query = [], ?array $json = null, array $headers = [], string $cookie = ''): Response
    {
        $body = fopen('php://memory', 'w+') ?: self::fail('stream');
        fwrite($body, $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR));
        rewind($body);
        $headers += ['host' => 'files.example.com', 'x-linkrot-setup' => '1'];
        if ($cookie !== '') {
            $headers['cookie'] = Setup::COOKIE . '=' . $cookie;
        }

        return $this->wizard()->handle(new Request($method, '/setup.php', $headers, $query, ['SCRIPT_NAME' => '/setup.php', 'HTTPS' => 'on'], $body));
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        $data = json_decode($response->body, true);
        self::assertIsArray($data, $response->body);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function login(): string
    {
        self::assertTrue($this->data($this->send('POST', json: ['action' => 'code']))['ok']);
        $content = (string) file_get_contents($this->root . '/' . SetupCode::FILE_NAME);
        if (preg_match('~^// CODE: (\S+)$~m', $content, $m) !== 1) {
            self::fail('no code in ' . $content);
        }
        $response = $this->send('POST', json: ['action' => 'login', 'code' => $m[1]]);
        self::assertTrue($this->data($response)['ok']);
        if (preg_match('~^' . Setup::COOKIE . '=([0-9a-f]{64}); Path=/; Max-Age=\d+; HttpOnly; SameSite=Strict; Secure$~', $response->headers['Set-Cookie'], $c) !== 1) {
            self::fail('cookie: ' . $response->headers['Set-Cookie']);
        }

        return $c[1];
    }

    public function testTheLoginPageSaysWhereTheCodeIsAndGivesNothingAway(): void
    {
        $response = $this->send('GET');

        self::assertSame(200, $response->status);
        self::assertStringContainsString(SetupCode::FILE_NAME, $response->body);
        self::assertStringContainsString("script-src 'self'", $response->headers['Content-Security-Policy']);
        self::assertStringNotContainsString('Requirements', $response->body);
        self::assertFileDoesNotExist($this->root . '/' . SetupCode::FILE_NAME, 'only a request for a code writes one');
    }

    public function testTheCodeIsAPhpFileThatPrintsNothing(): void
    {
        $this->send('POST', json: ['action' => 'code']);

        $file = $this->root . '/' . SetupCode::FILE_NAME;
        ob_start();
        include $file;
        self::assertSame('', ob_get_clean(), 'served by the web server, it shows nothing');
        self::assertMatchesRegularExpression('~^// CODE: [0-9a-f]{32}$~m', (string) file_get_contents($file));
    }

    public function testAValidCodeIsNeverReplaced(): void
    {
        $this->send('POST', json: ['action' => 'code']);
        $first = file_get_contents($this->root . '/' . SetupCode::FILE_NAME);

        $this->send('POST', json: ['action' => 'code']);

        self::assertSame($first, file_get_contents($this->root . '/' . SetupCode::FILE_NAME), 'a visitor cannot keep the owner out by asking for codes');
    }

    public function testAWrongCodeDoesNotLogIn(): void
    {
        $this->send('POST', json: ['action' => 'code']);

        $response = $this->send('POST', json: ['action' => 'login', 'code' => str_repeat('0', 32)]);

        self::assertSame(403, $response->status);
        self::assertArrayNotHasKey('Set-Cookie', $response->headers);
    }

    public function testTheCodeExpiresAfterAnHourWithoutUse(): void
    {
        $cookie = $this->login();
        touch($this->root . '/' . SetupCode::FILE_NAME, $this->now - SetupCode::IDLE - 1);

        self::assertStringNotContainsString('Requirements', $this->send('GET', cookie: $cookie)->body);
        self::assertSame(403, $this->send('POST', json: ['action' => 'migrate'], cookie: $cookie)->status);
    }

    public function testActionsNeedTheHeaderAndTheSameOrigin(): void
    {
        $cookie = $this->login();

        self::assertSame(403, $this->send('POST', json: ['action' => 'finish'], headers: ['x-linkrot-setup' => ''], cookie: $cookie)->status, 'a form of another site cannot set the header');
        self::assertSame(403, $this->send('POST', json: ['action' => 'finish'], headers: ['sec-fetch-site' => 'cross-site'], cookie: $cookie)->status);
        self::assertSame(403, $this->send('POST', json: ['action' => 'finish'], headers: ['origin' => 'https://evil.example'], cookie: $cookie)->status);
        self::assertFileExists($this->root . '/' . SetupCode::FILE_NAME);
    }

    public function testTheProbesNeedALogin(): void
    {
        self::assertSame(403, $this->send('GET', ['probe' => 'echo'])->status);
        self::assertSame(403, $this->send('GET', ['probe' => 'send', 'mode' => 'php'])->status);
    }

    public function testTheMainPagePutsTheDataNextToTheDocumentRootAndWritesThePaths(): void
    {
        $cookie = $this->login();

        $page = $this->send('GET', cookie: $cookie);

        self::assertStringContainsString('Requirements', $page->body);
        self::assertDirectoryExists($this->base . '/linkrot-data');
        $paths = new SetupEnvironment($this->root, $this->root)->paths();
        self::assertSame($this->base . '/linkrot-data', $paths['data']);
        self::assertTrue($paths['auto_migrate']);
        self::assertStringContainsString('data-auto-probe="1"', $page->body, 'not tested yet: the browser starts the tests');
        self::assertStringContainsString('data-data-url=""', $page->body, 'outside the document root, there is no URL to try');
    }

    public function testWithoutWritePermissionNextToTheDocumentRootTheDataIsBelowItAndLocked(): void
    {
        chmod($this->base, 0o555);
        $cookie = $this->login();

        $page = $this->send('GET', cookie: $cookie);

        self::assertFileExists($this->root . '/linkrot-data/.htaccess');
        self::assertStringContainsString('Require all denied', (string) file_get_contents($this->root . '/linkrot-data/.htaccess'));
        self::assertStringContainsString('data-data-url="/linkrot-data/' . SetupEnvironment::PROBE_FILE . '"', $page->body);
    }

    public function testTheEchoProbeCountsTheBodyAndShowsTheAuthorization(): void
    {
        $cookie = $this->login();
        $this->send('GET', cookie: $cookie);
        $body = fopen('php://memory', 'w+') ?: self::fail('stream');
        fwrite($body, str_repeat('x', 3_000_000));
        rewind($body);

        $response = $this->wizard()->handle(new Request('PATCH', '/linkrot-setup-probe', ['cookie' => Setup::COOKIE . "=$cookie", 'authorization' => 'Bearer p'], ['probe' => 'echo'], [], $body));

        self::assertSame(['ok' => true, 'method' => 'PATCH', 'authorization' => 'Bearer p', 'length' => 3_000_000], $this->data($response));
    }

    public function testTheSendProbeUsesTheHeaderOfEachMode(): void
    {
        $cookie = $this->login();
        $this->send('GET', cookie: $cookie);

        $xsendfile = $this->send('GET', ['probe' => 'send', 'mode' => 'xsendfile'], cookie: $cookie);
        self::assertSame($this->base . '/linkrot-data/' . SetupEnvironment::PROBE_FILE, $xsendfile->headers['X-Sendfile']);
        self::assertSame(409, $this->send('GET', ['probe' => 'send', 'mode' => 'litespeed'], cookie: $cookie)->status, 'the data is outside the document root');
        self::assertSame($this->base . '/linkrot-data/' . SetupEnvironment::PROBE_FILE, $this->send('GET', ['probe' => 'send', 'mode' => 'php'], cookie: $cookie)->streamPath);
    }

    /** @return array<string, mixed> what a server where everything works answers */
    private function good(): array
    {
        return [
            'methods' => ['GET' => true, 'POST' => true, 'PUT' => true, 'PATCH' => true, 'DELETE' => true],
            'authorization' => true,
            'largest' => 33_554_432 + Setup::CHUNK_OVERHEAD,
            'reachable' => ['data' => false, 'app' => false],
            'modes' => ['xsendfile' => false, 'litespeed' => false, 'php' => true],
        ];
    }

    public function testTheDecision(): void
    {
        self::assertSame(['download_mode' => 'php', 'chunk_size' => 33_554_432, 'errors' => [], 'warnings' => []], Setup::decide($this->good(), true));

        $results = ['largest' => 9_000_000, 'modes' => ['xsendfile' => true, 'litespeed' => true, 'php' => true]] + $this->good();
        $decision = Setup::decide($results, false);
        self::assertSame('xsendfile', $decision['download_mode'], 'the web server sends the file if it can');
        self::assertSame(8_388_608, $decision['chunk_size'], 'the biggest chunk that fits into the request limit');
        self::assertCount(1, $decision['warnings']);

        self::assertNotSame([], Setup::decide(['reachable' => ['data' => true, 'app' => false]] + $this->good(), true)['errors'], 'data that can be fetched is an error');
        self::assertSame([], Setup::decide(['reachable' => ['app' => false]] + $this->good(), false)['errors'], 'data outside the document root needs no test');
        self::assertNotSame([], Setup::decide(['reachable' => ['data' => false]] + $this->good(), true)['errors'], 'code that was not tested counts as reachable');
        self::assertNotSame([], Setup::decide(['authorization' => false] + $this->good(), true)['errors']);
        self::assertNotSame([], Setup::decide(['methods' => ['GET' => true]] + $this->good(), true)['errors']);
        self::assertNotSame([], Setup::decide(['largest' => 500_000] + $this->good(), true)['errors']);
    }

    /** @param array<string, mixed> $results */
    private function apply(string $cookie, array $results): void
    {
        $this->send('GET', cookie: $cookie);
        $this->send('POST', json: ['action' => 'apply', 'results' => $results], cookie: $cookie);
    }

    public function testAHostCanOnlyBeAddedOnceTheTestsPassed(): void
    {
        $cookie = $this->login();
        $this->send('GET', cookie: $cookie);

        self::assertSame(409, $this->send('POST', json: ['action' => 'add_host', 'host' => 'files.example.com'], cookie: $cookie)->status, 'not tested yet');

        $this->apply($cookie, ['authorization' => false] + $this->good());
        self::assertSame(409, $this->send('POST', json: ['action' => 'add_host', 'host' => 'files.example.com'], cookie: $cookie)->status, 'tested with errors');
    }

    public function testAHostGetsItsDatabaseAndTheConfigurationOfTheTestsAndATokenWorks(): void
    {
        $cookie = $this->login();
        $this->apply($cookie, ['largest' => 2_200_000] + $this->good());

        $response = $this->send('POST', json: ['action' => 'add_host', 'host' => 'files.example.com', 'email' => 'me@example.com'], cookie: $cookie);
        self::assertTrue($this->data($response)['ok'], $response->body);

        $data = $this->base . '/linkrot-data';
        self::assertSame(['files.example.com'], Tenants::load($data . '/global-config.php')->hosts);
        $config = (string) file_get_contents($data . '/files.example.com/config.php');
        self::assertStringContainsString("'download_mode' => 'php'", $config);
        self::assertStringContainsString("'chunk_size' => 2097152", $config);
        self::assertStringContainsString("'email' => 'me@example.com'", $config);
        self::assertFileExists($data . '/files.example.com/linkrot.sqlite');

        $token = $this->data($this->send('POST', json: ['action' => 'token_new', 'host' => 'files.example.com', 'name' => 'laptop'], cookie: $cookie));
        self::assertMatchesRegularExpression('~^lr_~', (string) $token['token']);
        self::assertSame(409, $this->send('POST', json: ['action' => 'token_new', 'host' => 'files.example.com', 'name' => 'laptop'], cookie: $cookie)->status);
        self::assertStringContainsString('laptop', $this->send('GET', cookie: $cookie)->body);
        self::assertTrue($this->data($this->send('POST', json: ['action' => 'token_del', 'host' => 'files.example.com', 'name' => 'laptop'], cookie: $cookie))['ok']);

        self::assertTrue($this->data($this->send('POST', json: ['action' => 'rewrite', 'host' => ''], cookie: $cookie))['ok']);
        self::assertTrue($this->data($this->send('POST', json: ['action' => 'migrate'], cookie: $cookie))['ok']);
        self::assertTrue($this->data($this->send('POST', json: ['action' => 'cache_clear'], cookie: $cookie))['ok']);
    }

    public function testTestingAgainUpdatesTheHosts(): void
    {
        $cookie = $this->login();
        $this->apply($cookie, $this->good());
        $this->send('POST', json: ['action' => 'add_host', 'host' => 'files.example.com'], cookie: $cookie);

        $this->apply($cookie, ['modes' => ['xsendfile' => true, 'php' => true]] + $this->good());

        self::assertStringContainsString("'download_mode' => 'xsendfile'", (string) file_get_contents($this->base . '/linkrot-data/files.example.com/config.php'));
    }

    public function testTheSenderOfTheMailsIsCheckedAndKeptWithTheRest(): void
    {
        $cookie = $this->login();
        $this->apply($cookie, $this->good());
        $this->send('POST', json: ['action' => 'add_host', 'host' => 'files.example.com'], cookie: $cookie);

        self::assertSame(500, $this->send('POST', json: ['action' => 'mail_from', 'from' => 'not an address'], cookie: $cookie)->status);
        self::assertTrue($this->data($this->send('POST', json: ['action' => 'mail_from', 'from' => 'Linkrot <lr@example.com>'], cookie: $cookie))['ok']);

        $tenants = Tenants::load($this->base . '/linkrot-data/global-config.php');
        self::assertSame('Linkrot <lr@example.com>', $tenants->mailSender());
        self::assertSame(['files.example.com'], $tenants->hosts);
    }

    public function testFinishLocksTheSetup(): void
    {
        $cookie = $this->login();

        $response = $this->send('POST', json: ['action' => 'finish'], cookie: $cookie);

        self::assertTrue($this->data($response)['ok']);
        self::assertFileDoesNotExist($this->root . '/' . SetupCode::FILE_NAME);
        self::assertSame(403, $this->send('GET', ['probe' => 'echo'], cookie: $cookie)->status);
    }
}

<?php

declare(strict_types=1);

namespace Linkrot\Setup;

use Closure;
use InvalidArgumentException;
use Linkrot\Auth\TokenStore;
use Linkrot\Cli\HostConfigFile;
use Linkrot\Cli\HostsCommand;
use Linkrot\Config;
use Linkrot\Database;
use Linkrot\DownloadMode;
use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Mail\SendmailMailer;
use Linkrot\Pages\PageRenderer;
use Linkrot\Pages\PageStore;
use Linkrot\Tenants;
use Throwable;

/**
 * The setup of the shared hosting archive (setup.php): everything app/install.sh and app/bin do on a server with a
 * shell, in the browser. Only for whoever can read setup-code.php (SetupCode). The browser tests the web server
 * (setup.js against the probes here), the server decides from the results and writes the configuration.
 */
final readonly class Setup
{
    public const string COOKIE = 'linkrot_setup';
    public const string STATE_FILE = 'setup.json';
    public const string PROBE_PATH = 'linkrot-setup-probe';

    /** Chunk sizes the browser tries, biggest first; the default of the server is the biggest one. */
    public const array CHUNK_SIZES = [33_554_432, 16_777_216, 8_388_608, 4_194_304, 2_097_152, 1_000_000];
    /** What a chunk request carries besides the chunk (the digest) and some margin. */
    public const int CHUNK_OVERHEAD = 1024;

    private const array HEADERS = [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'X-Frame-Options' => 'DENY',
    ];

    private SetupCode $code;
    private Closure $clock;

    /** @param (Closure(): int)|null $clock */
    public function __construct(private SetupEnvironment $env, ?Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
        $this->code = new SetupCode($env->root, $this->clock);
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->dispatch($request);
        } catch (Throwable $e) {
            error_log('linkrot setup: ' . $e);

            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function dispatch(Request $request): Response
    {
        $asset = $request->query['asset'] ?? null;
        if ($asset === 'js' || $asset === 'css') {
            return $this->asset($asset);
        }
        $loggedIn = $this->code->validSession($this->cookie($request));
        $probe = $request->query['probe'] ?? null;
        if ($probe !== null) {
            return $loggedIn ? $this->probe($request, $probe) : $this->json(['ok' => false, 'error' => 'not logged in'], 403);
        }
        if ($request->method === 'GET' || $request->method === 'HEAD') {
            return $loggedIn ? $this->main($request) : $this->login();
        }
        if ($request->method !== 'POST') {
            return $this->json(['ok' => false, 'error' => 'method not allowed'], 405);
        }
        // Only setup.js posts, with a header a form of another site cannot send, from this site.
        if ($request->header('x-linkrot-setup') !== '1' || !$this->sameOrigin($request)) {
            return $this->json(['ok' => false, 'error' => 'refused: not from the setup page'], 403);
        }
        $input = json_decode((string) stream_get_contents($request->body(), 1 << 20), true);
        $input = is_array($input) ? $input : [];
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';

        if ($action === 'code') {
            return $this->code->create()
                ? $this->json(['ok' => true, 'message' => 'The code is in ' . SetupCode::FILE_NAME . ' next to setup.php: open it with FTP or the file manager of your hoster.'])
                : $this->json(['ok' => false, 'error' => 'PHP may not write ' . $this->code->path() . '. Create it yourself with a line "// CODE: " followed by a secret of at least ' . SetupCode::MIN_LENGTH . ' characters, then enter that secret.']);
        }
        if ($action === 'login') {
            if (!$this->code->matches($this->string($input, 'code'))) {
                usleep(500_000);

                return $this->json(['ok' => false, 'error' => 'wrong or expired code'], 403);
            }

            return $this->json(['ok' => true], headers: ['Set-Cookie' => $this->setCookie($request, (string) $this->code->session())]);
        }
        if (!$loggedIn) {
            return $this->json(['ok' => false, 'error' => 'not logged in (the code expires after an hour without use)'], 403);
        }

        return match ($action) {
            'apply' => $this->apply(is_array($input['results'] ?? null) ? $input['results'] : []),
            'add_host' => $this->addHost($this->string($input, 'host'), $this->string($input, 'email')),
            'rewrite' => $this->rewrite($this->string($input, 'host')),
            'token_new' => $this->tokenNew($this->string($input, 'host'), $this->string($input, 'name')),
            'token_del' => $this->tokenDel($this->string($input, 'host'), $this->string($input, 'name')),
            'migrate' => $this->migrate(),
            'cache_clear' => $this->cacheClear(),
            'mail_from' => $this->mailFrom($this->string($input, 'from')),
            'mail_test' => $this->mailTest($this->string($input, 'to')),
            'finish' => $this->finish($request),
            default => $this->json(['ok' => false, 'error' => "unknown action '$action'"], 400),
        };
    }

    /** @param array<array-key, mixed> $input */
    private function string(array $input, string $key): string
    {
        return is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    }

    private function cookie(Request $request): ?string
    {
        foreach (explode(';', (string) $request->header('cookie')) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($name === self::COOKIE) {
                return $value;
            }
        }

        return null;
    }

    private function basePath(Request $request): string
    {
        return rtrim(str_replace('\\', '/', dirname($request->server['SCRIPT_NAME'] ?? '/setup.php')), '/');
    }

    private function https(Request $request): bool
    {
        $https = $request->server['HTTPS'] ?? '';

        return ($https !== '' && $https !== 'off') || strtolower((string) $request->header('x-forwarded-proto')) === 'https';
    }

    private function setCookie(Request $request, string $value, int $maxAge = SetupCode::IDLE * 24): string
    {
        return self::COOKIE . '=' . $value . '; Path=' . $this->basePath($request) . '/; Max-Age=' . $maxAge
            . '; HttpOnly; SameSite=Strict' . ($this->https($request) ? '; Secure' : '');
    }

    /** Sec-Fetch-Site or Origin, where the browser sends them, must say this site. */
    private function sameOrigin(Request $request): bool
    {
        $site = $request->header('sec-fetch-site');
        if ($site !== null && $site !== 'same-origin') {
            return false;
        }
        $origin = $request->header('origin');

        return $origin === null || parse_url($origin, PHP_URL_HOST) === parse_url('http://' . $request->header('host'), PHP_URL_HOST);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function json(array $data, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers + self::HEADERS, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    // --- state ---

    private function dataDir(): string
    {
        return $this->env->dataDir() ?? throw new \RuntimeException('there is no data folder yet: reload the setup page');
    }

    /** @return array<string, mixed> what the last probes found, [] before the first run */
    private function state(): array
    {
        $dir = $this->env->dataDir();
        $raw = $dir === null ? false : @file_get_contents($dir . '/' . self::STATE_FILE);
        $state = $raw === false ? null : json_decode($raw, true);

        return is_array($state) ? array_filter($state, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }

    private function tenants(): ?Tenants
    {
        $file = $this->dataDir() . '/' . Tenants::FILE_NAME;

        return is_file($file) ? Tenants::load($file) : null;
    }

    // --- probes ---

    private function probe(Request $request, string $probe): Response
    {
        if ($probe === 'echo') {
            // reads the body as a chunk upload would, without keeping it
            $length = 0;
            $body = $request->body();
            while (!feof($body)) {
                $block = fread($body, 1 << 20);
                if ($block === false || $block === '') {
                    break;
                }
                $length += strlen($block);
            }

            return $this->json(['ok' => true, 'method' => $request->method, 'authorization' => $request->header('authorization'), 'length' => $length]);
        }
        if ($probe === 'send') {
            $file = $this->env->probeFile($this->dataDir());
            $headers = ['Content-Type' => 'application/octet-stream'] + self::HEADERS;

            return match ($request->query['mode'] ?? '') {
                'xsendfile' => new Response(200, $headers + ['X-Sendfile' => $file]),
                'litespeed' => ($uri = $this->env->urlPath(dirname($file))) === null
                    ? $this->json(['ok' => false, 'error' => 'the data is not below the document root'], 409)
                    : new Response(200, $headers + ['X-LiteSpeed-Location' => $uri . '/' . SetupEnvironment::PROBE_FILE]),
                default => new Response(200, $headers + ['Content-Length' => (string) filesize($file)], streamPath: $file),
            };
        }

        return $this->json(['ok' => false, 'error' => 'unknown probe'], 404);
    }

    /**
     * Turns what the browser found into the configuration of the hosts: the download mode, the chunk size, and errors
     * that keep the setup from adding hosts.
     *
     * @param array<array-key, mixed> $results
     *
     * @return array{download_mode: string, chunk_size: int|null, errors: list<string>, warnings: list<string>}
     */
    public static function decide(array $results, bool $dataPublic): array
    {
        $errors = [];
        $warnings = [];
        $methods = is_array($results['methods'] ?? null) ? $results['methods'] : [];
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            if (($methods[$method] ?? false) !== true) {
                $errors[] = $method === 'GET'
                    ? 'requests for other paths do not reach PHP: mod_rewrite or the .htaccess next to setup.php does not work (AllowOverride)'
                    : "$method requests do not reach PHP (a firewall of the hoster, mod_security?): uploads and the API need it";
            }
        }
        if (($results['authorization'] ?? false) !== true) {
            $errors[] = 'the Authorization header does not reach PHP: every request with a token would fail (the .htaccess passes it on with mod_rewrite; ask the hoster for CGIPassAuth)';
        }
        $reachable = is_array($results['reachable'] ?? null) ? $results['reachable'] : [];
        if ($dataPublic && ($reachable['data'] ?? true) !== false) {
            $errors[] = 'the data folder can be fetched over the web: anybody could download every file. The .htaccess in it does not work on this server';
        }
        if (($reachable['app'] ?? true) !== false) {
            $errors[] = 'the code folder linkrot-app can be fetched over the web: its .htaccess does not work on this server';
        }
        $chunk = null;
        $largest = is_int($results['largest'] ?? null) ? $results['largest'] : 0;
        foreach (self::CHUNK_SIZES as $size) {
            if ($largest >= $size + self::CHUNK_OVERHEAD) {
                $chunk = $size;
                break;
            }
        }
        if ($chunk === null) {
            $errors[] = 'the web server does not take a request body of 1 MB: uploads cannot work';
        } elseif ($chunk < self::CHUNK_SIZES[0]) {
            $warnings[] = 'the web server limits request bodies: uploads use chunks of ' . round($chunk / 1_048_576, 1) . ' MiB';
        }
        $modes = is_array($results['modes'] ?? null) ? $results['modes'] : [];
        $mode = DownloadMode::Php->value;
        foreach ([DownloadMode::Xsendfile, DownloadMode::Litespeed] as $candidate) {
            if (($modes[$candidate->value] ?? false) === true) {
                $mode = $candidate->value;
                break;
            }
        }
        if ($mode === DownloadMode::Php->value && ($modes['php'] ?? false) !== true) {
            $errors[] = 'not even PHP itself can send a file';
        }

        return ['download_mode' => $mode, 'chunk_size' => $chunk, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** @param array<array-key, mixed> $results */
    private function apply(array $results): Response
    {
        $data = $this->dataDir();
        $decision = self::decide($results, $this->env->isPublic($data));
        [$journal, $why] = $this->env->journal($data);
        $state = $decision + ['journal' => $journal, 'journal_reason' => $why, 'results' => $results, 'at' => ($this->clock)()];
        file_put_contents($data . '/' . self::STATE_FILE, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $updated = [];
        $tenants = $this->tenants();
        if ($tenants instanceof \Linkrot\Tenants && $decision['errors'] === []) {
            if ($tenants->sqliteJournal !== $journal) {
                new Tenants($tenants->hosts, $tenants->mailFrom, $tenants->mailName, $journal)->save($data . '/' . Tenants::FILE_NAME);
            }
            foreach ($tenants->hosts as $host) {
                $this->configure($host, $decision);
                $updated[] = $host;
            }
        }

        return $this->json(['ok' => $decision['errors'] === []] + $decision + ['journal' => $journal, 'updated' => $updated]);
    }

    /**
     * Writes download_mode and chunk_size of the probes into the config.php of a host, keeping its other values.
     *
     * @param array{download_mode: string, chunk_size: int|null} $decision
     */
    private function configure(string $host, array $decision): void
    {
        $file = $this->dataDir() . "/$host/config.php";
        $baseUrl = Config::baseUrlFor($host);
        $values = is_file($file) ? HostConfigFile::values($file, $baseUrl) : [];
        $values['download_mode'] = $decision['download_mode'];
        if ($decision['chunk_size'] !== null) {
            $values['chunk_size'] = $decision['chunk_size'];
        }
        HostConfigFile::write($file, $values, $baseUrl);
    }

    // --- actions ---

    /**
     * Runs a command line tool of app/bin in-process and returns what it printed.
     *
     * @param list<string> $args
     *
     * @return array{bool, string}
     */
    private function hosts(array $args): array
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('memory stream');
        $code = new HostsCommand($this->dataDir(), $out, $out)->run($args);
        rewind($out);

        return [$code === HostsCommand::OK, (string) stream_get_contents($out)];
    }

    private function addHost(string $host, string $email): Response
    {
        $state = $this->state();
        if (!isset($state['download_mode'])) {
            return $this->json(['ok' => false, 'error' => 'the web server has not been tested yet: wait for the tests of the setup page to finish'], 409);
        }
        if (is_array($state['errors'] ?? null) && $state['errors'] !== []) {
            return $this->json(['ok' => false, 'error' => 'the tests of the web server found problems, fix them first: ' . implode('; ', array_filter($state['errors'], is_string(...)))], 409);
        }
        if (Tenants::normalize($host) === null || Tenants::normalize($host) !== strtolower($host)) {
            return $this->json(['ok' => false, 'error' => "not a plain hostname: $host"], 400);
        }
        $host = strtolower($host);
        [$ok, $output] = $this->hosts($email === '' ? ['add', $host] : ['add', $host, '--email', $email]);
        if (!$ok) {
            return $this->json(['ok' => false, 'error' => trim($output)], 400);
        }
        $data = $this->dataDir();
        $tenants = Tenants::load($data . '/' . Tenants::FILE_NAME);
        $journal = is_string($state['journal'] ?? null) ? $state['journal'] : Tenants::JOURNAL_DELETE;
        if ($tenants->sqliteJournal !== $journal) {
            new Tenants($tenants->hosts, $tenants->mailFrom, $tenants->mailName, $journal)->save($data . '/' . Tenants::FILE_NAME);
        }
        $this->configure($host, [
            'download_mode' => is_string($state['download_mode']) ? $state['download_mode'] : DownloadMode::Php->value,
            'chunk_size' => is_int($state['chunk_size'] ?? null) ? $state['chunk_size'] : null,
        ]);
        Database::openMigrated("$data/$host/linkrot.sqlite", $journal);

        return $this->json(['ok' => true, 'message' => trim($output) . "\ndatabase created, config.php written"]);
    }

    private function host(string $host): string
    {
        $tenants = $this->tenants() ?? throw new InvalidArgumentException('there is no host yet');

        return $tenants->select($host);
    }

    private function rewrite(string $host): Response
    {
        [$ok, $output] = $this->hosts($host === '' ? ['rewrite'] : ['rewrite', $this->host($host)]);

        return $this->json(['ok' => $ok, 'message' => trim($output)]);
    }

    private function tokenNew(string $host, string $name): Response
    {
        $host = $this->host($host);
        if (!TokenStore::isValidName($name)) {
            return $this->json(['ok' => false, 'error' => 'a token name is 1-64 letters, digits, dots, dashes or underscores'], 400);
        }
        $store = new TokenStore(Database::openCurrent($this->dataDir() . "/$host/linkrot.sqlite"));
        if ($store->exists($name)) {
            return $this->json(['ok' => false, 'error' => "there is a token named $name already"], 409);
        }
        $token = $store->create($name, ($this->clock)());

        return $this->json(['ok' => true, 'token' => $token, 'message' => "The token for $name, shown only once. On the device:\n\nlr config --server https://$host --token $token"]);
    }

    private function tokenDel(string $host, string $name): Response
    {
        $host = $this->host($host);
        $deleted = new TokenStore(Database::openCurrent($this->dataDir() . "/$host/linkrot.sqlite"))->delete($name);

        return $this->json(['ok' => $deleted, 'message' => $deleted ? "removed $name" : "there is no token $name"]);
    }

    private function migrate(): Response
    {
        $tenants = $this->tenants() ?? throw new InvalidArgumentException('there is no host yet');
        $lines = [];
        foreach ($tenants->hosts as $host) {
            $pdo = Database::openMigrated($this->dataDir() . "/$host/linkrot.sqlite", $tenants->sqliteJournal);
            $lines[] = "$host: database schema is at version " . Database::schemaVersion($pdo);
        }

        return $this->json(['ok' => true, 'message' => implode("\n", $lines)]);
    }

    private function cacheClear(): Response
    {
        $tenants = $this->tenants() ?? throw new InvalidArgumentException('there is no host yet');
        $lines = [];
        foreach ($tenants->hosts as $host) {
            $removed = new PageStore($this->dataDir() . "/$host/pages", new PageRenderer($host), $this->clock)->clearCache();
            $lines[] = "$host: page cache cleared ($removed files)";
        }

        return $this->json(['ok' => true, 'message' => implode("\n", $lines)]);
    }

    private function mailFrom(string $from): Response
    {
        $file = $this->dataDir() . '/' . Tenants::FILE_NAME;
        $tenants = $this->tenants() ?? throw new InvalidArgumentException('add a host first');
        $mail = $from === '' ? '' : "    'mail' => ['from' => " . var_export($from, true) . "],\n";
        // checked the way the server reads it, before it replaces the file
        $test = $file . '.new';
        file_put_contents($test, "<?php\n\nreturn [\n    'hosts' => " . var_export($tenants->hosts, true) . ",\n$mail];\n");
        try {
            $checked = Tenants::load($test);
        } finally {
            @unlink($test);
        }
        new Tenants($tenants->hosts, $checked->mailFrom, $checked->mailName, $tenants->sqliteJournal)->save($file);

        return $this->json(['ok' => true, 'message' => $from === '' ? 'no mail is sent' : "mail.from is $from"]);
    }

    private function mailTest(string $to): Response
    {
        $tenants = $this->tenants();
        if (!$tenants instanceof \Linkrot\Tenants || $tenants->mailFrom === null) {
            return $this->json(['ok' => false, 'error' => 'set the sender (mail.from) first'], 409);
        }
        if (!Tenants::isValidEmail($to)) {
            return $this->json(['ok' => false, 'error' => "not a plain address: $to"], 400);
        }
        new SendmailMailer($tenants->mailFrom, $tenants->mailName)->send($to, 'linkrot: test mail', "This mail was sent by the setup of linkrot. If it arrived, tokens by mail work.\n");

        return $this->json(['ok' => true, 'message' => "mail() took the mail for $to: if it does not arrive (spam folder?), the hoster does not deliver mail from PHP"]);
    }

    private function finish(Request $request): Response
    {
        $this->code->destroy();

        return $this->json(['ok' => true, 'message' => 'The setup is locked: setup-code.php is gone.'], headers: ['Set-Cookie' => $this->setCookie($request, '', 0)]);
    }

    // --- pages ---

    private function h(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function page(string $title, string $body, string $data = ''): Response
    {
        $title = $this->h($title);
        $html = <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex">
            <title>linkrot setup: $title</title>
            <link rel="stylesheet" href="setup.php?asset=css">
            <script src="setup.php?asset=js" defer></script>
            </head>
            <body$data>
            <main>
            <h1>linkrot setup</h1>
            $body
            </main>
            </body>
            </html>

            HTML;

        return new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'",
        ] + self::HEADERS, $html);
    }

    private function login(): Response
    {
        $file = $this->h(SetupCode::FILE_NAME);
        $body = <<<HTML
            <p>Only the owner of this webspace may set up linkrot. To prove that it is you, the setup writes a code into
            the file <code>$file</code> next to <code>setup.php</code>. Nobody can read it over the web; open it with FTP
            or the file manager of your hoster.</p>
            <form data-action="code" data-reload="0"><button>Write a code into $file</button></form>
            <form data-action="login" data-reload="1">
            <label>Code <input name="code" autocomplete="off" required size="40"></label>
            <button>Log in</button>
            </form>
            <pre class="output" hidden></pre>
            HTML;
        return $this->page('log in', $body);
    }

    private function main(Request $request): Response
    {
        [$data, $outside] = $this->env->placeData();
        $probeFile = $this->env->probeFile($data);
        $state = $this->state();
        $tenants = $this->tenants();
        $base = $this->basePath($request);

        $sections = [];
        $rows = '';
        foreach ($this->env->requirements() as $check) {
            $rows .= '<tr class="' . $this->h($check['level']) . '"><td>' . $this->h($check['name']) . '</td><td>' . $this->h($check['level']) . '</td><td>' . $this->h($check['message']) . '</td></tr>';
        }
        if (!$this->https($request)) {
            $rows .= '<tr class="warn"><td>https</td><td>warn</td><td>this page came over plain http: the links of linkrot are https://, the host needs a certificate</td></tr>';
        }
        $sections[] = "<section><h2>Requirements</h2><table>$rows</table></section>";

        [$journal, $why] = $this->env->journal($data);
        $where = $outside ? 'outside the document root: the web server cannot hand out anything in it' : 'below the document root (PHP may not create a folder next to it), locked with a .htaccess; the tests below check that it holds';
        $sections[] = '<section><h2>Data</h2><p><code>' . $this->h($data) . '</code>, ' . $this->h($where) . '.</p><p>Journal of the databases: <b>' . $this->h($journal) . '</b> (' . $this->h($why) . ').</p></section>';

        $tested = isset($state['download_mode']);
        $results = '';
        if ($tested) {
            $errors = is_array($state['errors'] ?? null) ? array_filter($state['errors'], is_string(...)) : [];
            $warnings = is_array($state['warnings'] ?? null) ? array_filter($state['warnings'], is_string(...)) : [];
            foreach ($errors as $error) {
                $results .= '<li class="error">' . $this->h($error) . '</li>';
            }
            foreach ($warnings as $warning) {
                $results .= '<li class="warn">' . $this->h($warning) . '</li>';
            }
            $chunk = is_int($state['chunk_size'] ?? null) ? round($state['chunk_size'] / 1_048_576, 1) . ' MiB' : 'none';
            $results .= '<li class="ok">downloads: <b>' . $this->h(is_string($state['download_mode']) ? $state['download_mode'] : '') . "</b>, upload chunks: <b>$chunk</b></li>";
        }
        $sections[] = '<section><h2>Web server</h2><p>Your browser tests the web server: requests through the front controller, '
            . 'the Authorization header, PUT/PATCH/DELETE, how big a request may be, that data and code cannot be fetched, '
            . 'and who can send a file.</p><ul class="results">' . $results . '</ul>'
            . '<p><button data-probe>' . ($tested ? 'Test again' : 'Test') . '</button> <span class="probe-status"></span></p></section>';

        $hosts = '';
        $first = '';
        foreach ($tenants->hosts ?? [] as $host) {
            $first = $first === '' ? $host : $first;
            $tokens = '';
            try {
                foreach (new TokenStore(Database::openCurrent("$data/$host/linkrot.sqlite"))->list() as $token) {
                    $tokens .= '<li>' . $this->h($token['name']) . ' <button data-action="token_del" data-host="' . $this->h($host) . '" data-name="' . $this->h($token['name']) . '">remove</button></li>';
                }
            } catch (Throwable $e) {
                $tokens = '<li class="error">' . $this->h($e->getMessage()) . ' (migrate below)</li>';
            }
            $hosts .= '<div class="host"><h3>' . $this->h($host) . '</h3><ul>' . ($tokens === '' ? '<li>no token yet</li>' : $tokens) . '</ul>'
                . '<form data-action="token_new" data-reload="0"><input type="hidden" name="host" value="' . $this->h($host) . '">'
                . '<label>New token for <input name="name" value="my-device" required size="16"></label> <button>Create</button></form>'
                . '<form data-action="rewrite"><input type="hidden" name="host" value="' . $this->h($host) . '"><button>Rewrite config.php</button></form></div>';
        }
        $suggest = $this->h(Tenants::normalize((string) $request->header('host')) ?? '');
        $hosts .= '<form data-action="add_host"><label>Add a host <input name="host" value="' . ($first === '' ? $suggest : '') . '" placeholder="files.example.com" required></label> '
            . '<label>address for tokens by mail (optional) <input name="email" type="email"></label> <button' . ($tested ? '' : ' disabled') . '>Add</button></form>';
        if ($first !== '') {
            $hosts .= '<form data-action="rewrite"><input type="hidden" name="host" value=""><button>Rewrite config.php of all hosts</button></form>';
        }
        $sections[] = "<section><h2>Hosts</h2>$hosts</section>";

        if ($tenants instanceof \Linkrot\Tenants) {
            $from = $this->h($tenants->mailSender() ?? '');
            $sections[] = '<section><h2>Mail</h2><p>Tokens by mail need a sender. PHP sends with <code>mail()</code>, through the hoster.</p>'
                . '<form data-action="mail_from"><label>Sender (mail.from) <input name="from" value="' . $from . '" placeholder="Linkrot &lt;linkrot@example.com&gt;" size="40"></label> <button>Save</button></form>'
                . '<form data-action="mail_test" data-reload="0"><label>Send a test mail to <input name="to" type="email" required></label> <button>Send</button></form></section>';
            $sections[] = '<section><h2>Maintenance</h2><p>The first request after an upgrade migrates by itself; this is for when it did not.</p>'
                . '<form data-action="migrate" data-reload="0"><button>Migrate the databases</button></form> '
                . '<form data-action="cache_clear" data-reload="0"><button>Clear the page cache</button></form></section>';
        }
        $sections[] = '<section><h2>Done?</h2><form data-action="finish"><button>Lock the setup (removes ' . $this->h(SetupCode::FILE_NAME) . ')</button></form></section>';
        $sections[] = '<pre class="output" hidden></pre>';

        $dataUrl = $outside ? '' : (string) $this->env->urlPath($data);
        $attributes = ' data-base="' . $this->h($base) . '" data-probe="' . $this->h($base . '/' . self::PROBE_PATH) . '"'
            . ' data-expected="' . $this->h((string) file_get_contents($probeFile)) . '"'
            . ' data-data-url="' . $this->h($dataUrl === '' ? '' : $dataUrl . '/' . SetupEnvironment::PROBE_FILE) . '"'
            . ' data-app-url="' . $this->h(($app = $this->env->urlPath($this->env->appDir())) === null ? '' : $app . '/composer.json') . '"'
            . ' data-litespeed="' . ($outside ? '0' : '1') . '"'
            . ' data-sizes="' . implode(',', array_map(static fn(int $size): int => $size + self::CHUNK_OVERHEAD, self::CHUNK_SIZES)) . '"'
            . ' data-auto-probe="' . ($tested ? '0' : '1') . '"';

        return $this->page('setup', implode("\n", $sections), $attributes);
    }

    private function asset(string $name): Response
    {
        $file = __DIR__ . '/assets/setup.' . $name;

        return new Response(200, [
            'Content-Type' => $name === 'js' ? 'text/javascript; charset=utf-8' : 'text/css; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ], (string) file_get_contents($file));
    }
}

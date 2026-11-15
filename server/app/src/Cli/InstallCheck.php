<?php

declare(strict_types=1);

namespace Linkrot\Cli;

use Closure;
use Linkrot\Auth\TokenStore;
use Throwable;

/**
 * The end-to-end check of `app/install.sh`: with a temporary token, ask the health endpoint, upload a small file
 * through the public URL (https://<host>), open its page, download it and check the headers that only the web server can get right.
 * It also stores and removes a (never published) page, which needs the pages folder of the host to be writable for php-fpm, and
 * runs the cleanup, which needs its lock file. The token, the file and the page are removed again, whatever happens.
 */
final readonly class InstallCheck
{
    /** Clearly a test token; a leftover of an interrupted run is replaced. */
    public const string TOKEN_NAME = 'test-install-check';

    private const string FILE_NAME = 'linkrot-install-check.txt';

    /** A draft: it is never shown in a footer or at its public URL. */
    private const string PAGE_SLUG = 'linkrot-install-check';

    /**
     * @param Closure(string, string, array<string, string>, string): array{int, array<string, string>, string} $http
     *        method, path, request headers, body; answers status, response headers (lower-case names) and body
     * @param Closure(string): void $out one line of progress or of a failure
     */
    public function __construct(
        private TokenStore $tokens,
        private Closure $http,
        private Closure $out,
    ) {}

    public function run(): bool
    {
        $this->tokens->delete(self::TOKEN_NAME);
        $token = $this->tokens->create(self::TOKEN_NAME);
        $fileId = null;
        $pageStored = false;
        try {
            return $this->steps($token, $fileId, $pageStored);
        } catch (Throwable $e) {
            $this->fail($e->getMessage());

            return false;
        } finally {
            if ($fileId !== null) {
                try {
                    ($this->http)('DELETE', '/api/v1/files/' . $fileId, ['authorization' => 'Bearer ' . $token], '');
                } catch (Throwable) {
                    ($this->out)('note: the check file ' . $fileId . ' could not be deleted, it expires by itself');
                }
            }
            if ($pageStored) {
                try {
                    ($this->http)('DELETE', '/api/v1/pages/' . self::PAGE_SLUG, ['authorization' => 'Bearer ' . $token], '');
                } catch (Throwable) {
                    ($this->out)('note: the check page ' . self::PAGE_SLUG . ' could not be deleted, it is a draft and harmless: lr page rm ' . self::PAGE_SLUG);
                }
            }
            $this->tokens->delete(self::TOKEN_NAME);
        }
    }

    private function steps(string $token, ?string &$fileId, bool &$pageStored): bool
    {
        $auth = ['authorization' => 'Bearer ' . $token];

        [$status, , $body] = ($this->http)('GET', '/api/v1/health', $auth, '');
        $health = json_decode($body, true);
        if ($status !== 200 || !is_array($health) || !is_array($health['checks'] ?? null)) {
            return $this->fail("health endpoint answered HTTP $status: " . $this->excerpt($body));
        }
        $worst = true;
        foreach ($health['checks'] as $check) {
            if (is_array($check) && is_string($check['level'] ?? null)) {
                $this->say("health {$check['name']}: {$check['level']}, {$check['message']}");
                $worst = $worst && $check['level'] !== 'error';
            }
        }
        if (!$worst) {
            return $this->fail('the health endpoint reports an error');
        }

        $content = 'linkrot install check ' . bin2hex(random_bytes(8)) . "\n";
        [$status, , $body] = ($this->http)('POST', '/api/v1/files', $auth + ['content-type' => 'application/json'], json_encode([
            'name' => self::FILE_NAME,
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'rm_after' => '1h',
        ], JSON_THROW_ON_ERROR));
        $created = json_decode($body, true);
        if ($status !== 201 || !is_array($created) || !is_string($created['id'] ?? null)) {
            return $this->fail("creating a file answered HTTP $status: " . $this->excerpt($body));
        }
        $fileId = $created['id'];

        // the server dictates the chunk size; a real one is far larger than the check file, a test setup may not be
        $chunkSize = is_int($created['chunk_size'] ?? null) && $created['chunk_size'] > 0 ? $created['chunk_size'] : strlen($content);
        $ready = null;
        for ($offset = 0; $offset < strlen($content); $offset += $chunkSize) {
            $chunk = substr($content, $offset, $chunkSize);
            [$status, , $body] = ($this->http)('PATCH', "/api/v1/files/$fileId/content", $auth + [
                'content-type' => 'application/offset+octet-stream',
                'upload-offset' => (string) $offset,
            ], $chunk . hash('sha256', $chunk, true));
            $ready = json_decode($body, true);
            if ($status !== 200) {
                return $this->fail("uploading a chunk answered HTTP $status: " . $this->excerpt($body));
            }
        }
        if (!is_array($ready) || ($ready['status'] ?? null) !== 'ready' || !is_string($ready['url'] ?? null)) {
            return $this->fail('the upload did not end with a ready file: ' . $this->excerpt($body));
        }
        $this->say('upload: ok, ' . $ready['url']);

        $page = parse_url($ready['url'], PHP_URL_PATH);
        [$status, ,] = ($this->http)('GET', is_string($page) ? $page : "/file/$fileId", [], '');
        if ($status !== 200) {
            return $this->fail("the file page answered HTTP $status");
        }
        $this->say('file page: ok');

        [$status, $headers, $body] = ($this->http)('GET', "/dl/$fileId/" . self::FILE_NAME, [], '');
        if ($status !== 200 || $body !== $content) {
            return $this->fail("the download answered HTTP $status" . ($status === 200 ? ' with different bytes' : '') . '; behind nginx: is app_data/files reachable for the web server (see docs/DEPLOYMENT.md)?');
        }
        $this->say('download: ok, the bytes match');

        // all of these run, so that one problem does not hide the next
        $headersOk = $this->downloadHeaders($headers, $this->xRotExpected($auth));
        $pagesOk = $this->pages($auth, $pageStored);
        $cleanupOk = $this->cleanup($auth);

        return $headersOk && $pagesOk && $cleanupOk;
    }

    /**
     * Stores a draft page, has it rendered and removes it (the caller does that if this returns early): php-fpm has to
     * create and write the pages folder of the host, which nothing else in this check does.
     *
     * @param array<string, string> $auth
     */
    private function pages(array $auth, bool &$stored): bool
    {
        $hint = 'the pages folder of the host cannot be written: it and the host folder must be writable by the user php-fpm runs as';
        $path = '/api/v1/pages/' . self::PAGE_SLUG;
        [$status, , $body] = ($this->http)('PUT', $path, $auth, "---\ntitle: Install check\npublished: false\n---\n# Install check\n");
        if ($status !== 200) {
            return $this->fail("storing a page answered HTTP $status: " . $this->excerpt($body) . "; $hint");
        }
        $stored = true;
        [$status, , $body] = ($this->http)('GET', $path . '?format=html', $auth, '');
        if ($status !== 200 || !str_contains($body, 'Install check')) {
            return $this->fail("rendering the page answered HTTP $status: " . $this->excerpt($body) . "; $hint");
        }
        [$status, , $body] = ($this->http)('DELETE', $path, $auth, '');
        if ($status !== 200 && $status !== 204) {
            return $this->fail("removing the page answered HTTP $status: " . $this->excerpt($body) . "; $hint");
        }
        $stored = false;
        $this->say('pages: stored, rendered and removed again');

        return true;
    }

    /**
     * Runs the cleanup once, which needs its lock file in the data folder.
     *
     * @param array<string, string> $auth
     */
    private function cleanup(array $auth): bool
    {
        [$status, , $body] = ($this->http)('POST', '/api/v1/cleanup', $auth, '');
        $answer = json_decode($body, true);
        if ($status !== 200 || !is_array($answer)) {
            $message = is_array($answer) && is_array($answer['error'] ?? null) && is_string($answer['error']['message'] ?? null) ? $answer['error']['message'] : $this->excerpt($body);

            return $this->fail("the cleanup answered HTTP $status: $message");
        }
        $this->say('cleanup: ' . (($answer['ran'] ?? false) === true ? 'ran' : 'another cleanup is running right now, fine'));

        return true;
    }

    /** A host that switched the taglines off (`show_taglines`) sends no X-Rot header, so there is nothing to check. */
    /** @param array<string, string> $auth */
    private function xRotExpected(array $auth): bool
    {
        [$status, , $body] = ($this->http)('GET', '/api/v1/settings', $auth, '');
        $settings = json_decode($body, true);

        return !($status === 200 && is_array($settings) && ($settings['settings']['show_taglines'] ?? true) === false);
    }

    /** @param array<string, string> $headers lower-case names */
    private function downloadHeaders(array $headers, bool $xRotExpected): bool
    {
        $problems = [];
        if (!str_starts_with($headers['content-disposition'] ?? '', 'attachment')) {
            $problems[] = 'Content-Disposition is not `attachment`';
        }
        if (strtolower($headers['x-content-type-options'] ?? '') !== 'nosniff') {
            $problems[] = 'X-Content-Type-Options: nosniff is missing (add_header in the /_files/ location, docs/nginx.conf.example)';
        }
        if ($xRotExpected && ($headers['x-rot'] ?? '') === '') {
            $problems[] = 'the X-Rot header is missing (nginx drops it unless the /_files/ location has `add_header X-Rot $upstream_http_x_rot always;`, docs/nginx.conf.example)';
        }
        foreach ($problems as $problem) {
            $this->fail($problem);
        }
        if ($problems === []) {
            $this->say('download headers: ok, ' . ($xRotExpected ? 'X-Rot: ' . $headers['x-rot'] : 'no X-Rot, the taglines are switched off (show_taglines)'));
        }

        return $problems === [];
    }

    private function say(string $line): void
    {
        ($this->out)('ok: ' . $line);
    }

    private function fail(string $message): bool
    {
        ($this->out)('FAILED: ' . $message);

        return false;
    }

    private function excerpt(string $body): string
    {
        return substr(trim(preg_replace('~\s+~', ' ', $body) ?? ''), 0, 200);
    }
}

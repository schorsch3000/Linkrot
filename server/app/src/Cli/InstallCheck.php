<?php

declare(strict_types=1);

namespace Linkrot\Cli;

use Closure;
use Linkrot\Auth\TokenStore;
use Throwable;

/**
 * The end-to-end check of `app/install.sh`: with a temporary token, ask the health endpoint, upload a small file
 * through the public base_url, open its page, download it and check the headers that only the web server can get right.
 * The token and the file are removed again, whatever happens.
 */
final readonly class InstallCheck
{
    /** Clearly a test token; a leftover of an interrupted run is replaced. */
    public const string TOKEN_NAME = 'test-install-check';

    private const string FILE_NAME = 'linkrot-install-check.txt';

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
        try {
            return $this->steps($token, $fileId);
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
            $this->tokens->delete(self::TOKEN_NAME);
        }
    }

    private function steps(string $token, ?string &$fileId): bool
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

        return $this->downloadHeaders($headers);
    }

    /** @param array<string, string> $headers lower-case names */
    private function downloadHeaders(array $headers): bool
    {
        $problems = [];
        if (!str_starts_with($headers['content-disposition'] ?? '', 'attachment')) {
            $problems[] = 'Content-Disposition is not `attachment`';
        }
        if (strtolower($headers['x-content-type-options'] ?? '') !== 'nosniff') {
            $problems[] = 'X-Content-Type-Options: nosniff is missing (add_header in the /_files/ location, docs/nginx.conf.example)';
        }
        if (($headers['x-rot'] ?? '') === '') {
            $problems[] = 'the X-Rot header is missing (nginx drops it unless the /_files/ location has `add_header X-Rot $upstream_http_x_rot always;`, docs/nginx.conf.example)';
        }
        foreach ($problems as $problem) {
            $this->fail($problem);
        }
        if ($problems === []) {
            $this->say('download headers: ok, X-Rot: ' . $headers['x-rot']);
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

<?php

declare(strict_types=1);

namespace Linkrot\Http;

final readonly class Request
{
    /**
     * @param array<string, string> $headers lower-case header names
     * @param array<string, string> $query
     * @param array<string, string> $server relevant $_SERVER entries (REMOTE_ADDR, ...)
     * @param resource|null $bodyStream request body; php://input if null
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers = [],
        public array $query = [],
        public array $server = [],
        private mixed $bodyStream = null,
    ) {}

    /** @return resource */
    public function body(): mixed
    {
        return $this->bodyStream ?? fopen('php://input', 'rb') ?: throw new \RuntimeException('cannot open request body');
    }

    /**
     * Decodes a small JSON object body.
     *
     * @return array<string, mixed>|null null if the body is not a JSON object
     */
    public function jsonBody(int $limit = 65536): ?array
    {
        $raw = stream_get_contents($this->body(), $limit + 1);
        if ($raw === false || strlen($raw) > $limit) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) && !array_is_list($data) ? $data : null;
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        $server = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
            $server[$key] = $value;
        }
        $query = [];
        foreach ($_GET as $key => $value) {
            if (is_string($value)) {
                $query[(string) $key] = $value;
            }
        }

        return new self(strtoupper($server['REQUEST_METHOD'] ?? 'GET'), self::pathOf($server['REQUEST_URI'] ?? '/'), $headers, $query, $server);
    }

    /**
     * The path to route, from the request URI as nginx hands it on. nginx merges runs of slashes before it picks the
     * location (`//dl/x` is served by the `/dl/` location) but keeps the URI as it came, so the same is done here:
     * parse_url would take `dl` for a host and route `/x`, or give up on `///`. The query and a fragment are cut off,
     * a scheme and host of the absolute form too, and percent-decoding comes last (an encoded slash stays one segment
     * longer than the merge sees it, as before).
     */
    private static function pathOf(string $uri): string
    {
        $uri = preg_replace('~^[a-z][a-z0-9+.-]*://[^/?#]*~i', '', $uri) ?? $uri;
        $path = substr($uri, 0, strcspn($uri, '?#'));
        $path = preg_replace('~/{2,}~', '/', $path) ?? $path;
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return rawurldecode($path);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

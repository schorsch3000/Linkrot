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
        $uri = $server['REQUEST_URI'] ?? '/';
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));

        return new self(strtoupper($server['REQUEST_METHOD'] ?? 'GET'), $path, $headers, $query, $server);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace Linkrot\Http;

final readonly class Request
{
    /**
     * @param array<string, string> $headers lower-case header names
     * @param array<string, string> $query
     * @param array<string, string> $server relevant $_SERVER entries (REMOTE_ADDR, ...)
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers = [],
        public array $query = [],
        public array $server = [],
    ) {}

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

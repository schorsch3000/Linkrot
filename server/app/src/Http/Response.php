<?php

declare(strict_types=1);

namespace Linkrot\Http;

final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
        public ?string $streamPath = null,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Error body as documented in docs/API.md.
     *
     * @param array<string, mixed> $extra additional members of the error object (offset, id, url, ...)
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $code, string $message, array $extra = [], array $headers = []): self
    {
        $response = self::json(['error' => ['code' => $code, 'message' => $message] + $extra], $status);

        return new self($status, $headers + $response->headers, $response->body);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->streamPath !== null) {
            readfile($this->streamPath);

            return;
        }
        echo $this->body;
    }
}

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
        public int $streamOffset = 0,
        public ?int $streamLength = null,
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
            $this->stream($this->streamPath);

            return;
        }
        echo $this->body;
    }

    /** Block size of a streamed file: big enough to be cheap, small enough that an aborted client is noticed soon. */
    private const int STREAM_BLOCK = 1 << 20;

    /**
     * Sends $streamLength bytes (all if null) of the file from $streamOffset. A download may take longer than
     * max_execution_time allows, and is stopped as soon as the client is gone. Output buffers are the caller's business
     * (index.php closes them before a streamed response, so that a file never ends up in memory).
     */
    private function stream(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            error_log('linkrot: cannot open ' . $path);

            return;
        }
        try {
            if ($this->streamOffset > 0 && fseek($handle, $this->streamOffset) !== 0) {
                error_log('linkrot: cannot seek in ' . $path);

                return;
            }
            @set_time_limit(0);
            $left = $this->streamLength;
            while (!feof($handle) && ($left === null || $left > 0)) {
                $block = fread($handle, $left === null ? self::STREAM_BLOCK : min(self::STREAM_BLOCK, $left));
                if ($block === false || $block === '') {
                    break;
                }
                echo $block;
                flush();
                if ($left !== null) {
                    $left -= strlen($block);
                }
                if (connection_aborted() === 1) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }
    }
}

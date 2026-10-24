<?php

declare(strict_types=1);

namespace Linkrot\Auth;

use Closure;

/** Same semantics as the APCu limiter, for tests. */
final class InMemoryRateLimiter implements RateLimiter
{
    /** @var array<string, array{count: int, until: int}> */
    private array $windows = [];

    /** @param Closure(): int $clock */
    public function __construct(private readonly int $attempts, private readonly int $window, private readonly Closure $clock) {}

    public function blockedFor(string $clientIp): ?int
    {
        $entry = $this->current($clientIp);
        if ($entry === null || $entry['count'] < $this->attempts) {
            return null;
        }

        return max(1, $entry['until'] - ($this->clock)());
    }

    public function recordFailure(string $clientIp): void
    {
        $key = ClientKey::for($clientIp);
        $entry = $this->current($clientIp) ?? ['count' => 0, 'until' => ($this->clock)() + $this->window];
        ++$entry['count'];
        $this->windows[$key] = $entry;
    }

    /** @return array{count: int, until: int}|null */
    private function current(string $clientIp): ?array
    {
        $entry = $this->windows[ClientKey::for($clientIp)] ?? null;

        return $entry !== null && $entry['until'] > ($this->clock)() ? $entry : null;
    }
}

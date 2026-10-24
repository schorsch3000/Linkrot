<?php

declare(strict_types=1);

namespace Linkrot\Auth;

use RuntimeException;

/**
 * Fixed window limiter on APCu. apcu_add/apcu_inc are atomic and shared between php-fpm workers.
 * Counters are lost when php-fpm restarts, which is accepted.
 */
final readonly class ApcuRateLimiter implements RateLimiter
{
    private const string PREFIX = 'linkrot:rl:';

    public function __construct(private int $attempts, private int $window)
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            throw new RuntimeException('APCu is required for rate limiting but not enabled');
        }
    }

    public function blockedFor(string $clientIp): ?int
    {
        $key = self::PREFIX . ClientKey::for($clientIp);
        $count = apcu_fetch($key . ':n');
        if (!is_int($count) || $count < $this->attempts) {
            return null;
        }
        $until = apcu_fetch($key . ':t');

        return max(1, (is_int($until) ? $until : time() + $this->window) - time());
    }

    public function recordFailure(string $clientIp): void
    {
        $key = self::PREFIX . ClientKey::for($clientIp);
        // The window starts with the first failure; both entries expire together.
        apcu_add($key . ':t', time() + $this->window, $this->window);
        apcu_add($key . ':n', 0, $this->window);
        apcu_inc($key . ':n');
    }
}

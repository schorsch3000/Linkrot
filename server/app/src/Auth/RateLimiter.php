<?php

declare(strict_types=1);

namespace Linkrot\Auth;

/** Limits failed authentication per client. Deliberately independent of the database (no locking). */
interface RateLimiter
{
    /** @return int|null seconds until the client may try again, null if it is not blocked */
    public function blockedFor(string $clientIp): ?int;

    public function recordFailure(string $clientIp): void;
}

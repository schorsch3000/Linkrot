<?php

declare(strict_types=1);

namespace Linkrot\Auth;

use Linkrot\Http\Request;
use Linkrot\Http\Response;

/** Protects API routes: rate limit first (hard limit, also for valid tokens), then token check. */
final readonly class Guard
{
    public function __construct(private Authenticator $authenticator, private RateLimiter $limiter) {}

    /**
     * Records failed attempts, so repeated calls are not equivalent.
     *
     * @phpstan-impure
     *
     * @return Response|null null means the request is authenticated and may proceed
     */
    public function check(Request $request): ?Response
    {
        $ip = $request->server['REMOTE_ADDR'] ?? '';
        $blockedFor = $this->limiter->blockedFor($ip);
        if ($blockedFor !== null) {
            return Response::error(429, 'rate_limited', 'too many failed attempts', headers: ['Retry-After' => (string) $blockedFor]);
        }
        if ($this->authenticator->authenticate($request) === null) {
            $this->limiter->recordFailure($ip);

            return Response::error(401, 'unauthorized', 'missing or invalid token', headers: ['WWW-Authenticate' => 'Bearer']);
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Linkrot\Auth;

use Linkrot\Http\Request;

/** Extracts the bearer token from a request and checks it. */
final readonly class Authenticator
{
    public function __construct(private TokenStore $tokens) {}

    /** @return string|null name of the token if the request is authenticated */
    public function authenticate(Request $request): ?string
    {
        $header = $request->header('authorization');
        if ($header === null || preg_match('~^Bearer ([^\s]+)$~i', $header, $m) !== 1) {
            return null;
        }

        return $this->tokens->authenticate($m[1]);
    }
}

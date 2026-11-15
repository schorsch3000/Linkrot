<?php

declare(strict_types=1);

namespace Linkrot\Auth;

/** Knows one kind of bearer token. */
interface BearerVerifier
{
    /** @return string|null a name for the token if it is valid, null if it is unknown */
    public function authenticate(string $token, ?int $now = null): ?string;
}

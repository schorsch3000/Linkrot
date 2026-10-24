<?php

declare(strict_types=1);

namespace Linkrot\Auth;

/** Turns a client address into a stable, non-reversible key. IPv6 clients share their /64. */
final class ClientKey
{
    public static function for(string $ip): string
    {
        return hash('sha256', self::normalize($ip));
    }

    public static function normalize(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return 'invalid:' . $ip;
        }
        if (strlen($packed) === 4) {
            return $ip;
        }
        // IPv4-mapped IPv6 address (::ffff:a.b.c.d) is the IPv4 client.
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}

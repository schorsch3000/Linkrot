<?php

declare(strict_types=1);

namespace Linkrot\Web;

/** The tagline of the download response, for people who read headers: `X-Rot: <line>`. A file always gets the same line. */
final class HeaderTaglines
{
    public const string HEADER = 'X-Rot';

    /** Printable ASCII only, so that every server and client takes them as a header value. */
    private const array LINES = [
        'fresh; mold=0%; best-before=soon',
        'ripe; mold=low; spores=harmless',
        'overripe; mold=moderate; handle-with=tongs',
        'fermenting; smell=funky; max-age=eventually',
        'rotting=on-schedule; dignity=intact',
        'freshness=0.73; decay=nominal',
        'mold=pending; fungi=moving-in',
        'best-before=when-the-mold-arrives',
        'moisture=slightly; temporary=yes',
        'ttl=unknown; rot=certain',
        'preservatives=none; since=forever',
        'stored=counter; overnight=yes',
        'peak-freshness=now; trend=downhill',
        'decay=guaranteed; assembly=required',
        'keep-away=damp-servers',
        'status=fuzzy; handle=tongs',
        'marinating=bits; marinating=bytes',
        'specimen=fine; impermanence=high',
        'compost=planned; fertilizer=eventually',
        'mold-got-here-first=barely',
        'cache-control=ephemeral; mold=revalidate',
        'etag=W/"not-yet-rotten"; probably=yes',
        'vary=mold; vary=humidity',
        'warning=199 linkrot "slowly dissolving"',
        'link-rot=intentional; fresh-until=further-notice',
        'mold;dur=2.3, decay;dur=0.1;desc="nominal"',
        'fridge;desc="back shelf", compost;dur=86400000',
        '</fridge>; rel="storage"; title="back shelf"',
        '<https://compost.example/>; rel="next"; title="eventually"',
        'Mold/2.4 (Fedora; compost) Spores/0.9',
        'Penicillium/9.1 (Camembert) Yeast/3.0',
        'Sun, 06 Nov 2039 08:49:37 GMT; note=compost',
        'fresh;q=0.1, ripe;q=0.8, rotten;q=1.0',
        'mold-US, fungi;q=0.8, *;q=0.1',
        'bytes 0-1048576/overripe',
        'text/mold; charset=fungi',
        '1.1 fridge, 1.0 compost-heap',
        'for=mold;by=shelf;proto=compost',
        '120 (days, after the mold)',
        'GET, SNIFF, COMPOST',
        'HTTP/2.0, MOLD/1.0',
        'mold=1; Max-Age=0; SameSite=Fridge; Secure',
        'max-age=31536000; includeSubDomains; preload-compost',
        "default-src 'none'; mold-src 'self'",
        'smell=(self), fridge=(), mold=*',
        'Basil realm="herb garden", charset="UTF-8"',
        '100-compost',
        'h3=":443"; ma=0; persist=fridge',
        'gzip, fermented',
        'chunked, moldy',
        '9999999 (days, give or take a mold)',
        '0; url=/compost',
        'sha-256=:bW9sZA==:',
        'fridge; hit; ttl=-1',
        'u=7, i',
        'W/"spores-1", "mold-2"',
        'bytes=0-; if-range=moldy',
        '*; mold=allowed, spores=denied',
        'timeout=5, max=0 (mold)',
        'nosniff; smell=ok',
        'same-origin; fridge=same-site',
        'attachment; filename="mold.bin"; rot=yes',
        'identity;q=0, fermented;q=1',
        'cross-origin; mold=require-corp',
        '?1; fresh=?0',
    ];

    public static function pick(string $seed): string
    {
        return self::LINES[crc32($seed) % count(self::LINES)];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return self::LINES;
    }
}

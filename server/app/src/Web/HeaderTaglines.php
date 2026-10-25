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

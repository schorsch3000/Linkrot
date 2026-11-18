<?php

declare(strict_types=1);

namespace Linkrot;

/** Who sends the bytes of a download. PHP decides in every mode (expiry, uses); only the sending differs. */
enum DownloadMode: string
{
    /** nginx sends the file (X-Accel-Redirect to its internal /_files/ location). */
    case Xaccel = 'xaccel';

    /** Apache with mod_xsendfile (or a server that speaks the same header) sends the file: X-Sendfile with the path. */
    case Xsendfile = 'xsendfile';

    /** LiteSpeed sends the file: X-LiteSpeed-Location with its URI, so the data must be below the document root. */
    case Litespeed = 'litespeed';

    /** PHP streams the file itself, with single ranges: shared hosting without any of the above, and development. */
    case Php = 'php';

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn(self $mode): string => $mode->value, self::cases());
    }
}

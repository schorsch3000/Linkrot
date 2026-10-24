<?php

declare(strict_types=1);

namespace Linkrot;

enum DownloadMode: string
{
    /** nginx sends the file (X-Accel-Redirect); production. */
    case Xaccel = 'xaccel';

    /** PHP streams the file; development and end-to-end tests only. */
    case Php = 'php';
}

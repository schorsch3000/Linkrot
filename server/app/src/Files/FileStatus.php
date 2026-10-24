<?php

declare(strict_types=1);

namespace Linkrot\Files;

enum FileStatus: string
{
    case Uploading = 'uploading';
    case Ready = 'ready';
}

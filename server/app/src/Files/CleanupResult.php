<?php

declare(strict_types=1);

namespace Linkrot\Files;

final readonly class CleanupResult
{
    /** @param int $filesFailed data files that exist but could not be removed */
    public function __construct(public int $rowsDeleted, public int $filesDeleted, public int $filesFailed = 0) {}
}

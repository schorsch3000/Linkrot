<?php

declare(strict_types=1);

namespace Linkrot\Files;

final readonly class CleanupResult
{
    public function __construct(public int $rowsDeleted, public int $filesDeleted) {}
}

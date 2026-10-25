<?php

declare(strict_types=1);

namespace Linkrot\Files;

/** What the cleanup wrote down about itself, for the health endpoint. */
final readonly class CleanupStatus
{
    public function __construct(
        public ?int $lastOkAt,
        public ?int $lastErrorAt,
        public ?string $lastError,
    ) {}
}

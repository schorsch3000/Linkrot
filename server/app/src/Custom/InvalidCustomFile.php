<?php

declare(strict_types=1);

namespace Linkrot\Custom;

use RuntimeException;

/** An upload that cannot be used; `status` is the HTTP status to answer with (400, 413). */
final class InvalidCustomFile extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

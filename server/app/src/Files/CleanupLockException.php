<?php

declare(strict_types=1);

namespace Linkrot\Files;

use RuntimeException;

/** The cleanup lock file cannot be opened, so no cleanup can run at all. */
final class CleanupLockException extends RuntimeException {}

<?php

declare(strict_types=1);

namespace Linkrot;

use RuntimeException;

/** The database is not at the schema version this release needs; `app/install.sh` migrates it. */
final class SchemaOutdatedException extends RuntimeException {}

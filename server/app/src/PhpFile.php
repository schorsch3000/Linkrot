<?php

declare(strict_types=1);

namespace Linkrot;

use InvalidArgumentException;

/**
 * Loads a configuration file that returns an array (config.php, global-config.php).
 *
 * Whatever such a file prints is not part of the configuration but would be sent to every client in front of the
 * answer: spaces before `<?php` (copied from an indented example), a byte order mark, text after `?>`. JSON clients do not
 * notice, but a Markdown or text download starts with it. The output is therefore always discarded; `strict` (the check
 * tools) turns it into an error so that the operator removes it, otherwise it is only logged.
 */
final class PhpFile
{
    public static function load(string $file, string $label, bool $strict = false): mixed
    {
        ob_start();
        try {
            $values = (static fn(): mixed => require $file)();
        } finally {
            $stray = (string) ob_get_clean();
        }
        if ($stray !== '') {
            $message = "$label: $file prints output (" . strlen($stray) . ' bytes: ' . json_encode(substr($stray, 0, 20)) . ') before `<?php` or after `?>`; '
                . 'the file must start with `<?php` in the first column, without spaces or a byte order mark';
            if ($strict) {
                throw new InvalidArgumentException($message);
            }
            error_log('linkrot: ' . $message . ' (ignored)');
        }

        return $values;
    }
}

<?php

declare(strict_types=1);

namespace Linkrot\Custom;

/** Checks an uploaded list of lines (taglines, headerlines) and turns it into the lines that are used. */
final class LineFile
{
    /** How many offending lines an error message names. */
    private const int REPORTED = 5;

    /**
     * Empty lines are skipped, spaces around a line are dropped, a repeated line is kept once.
     *
     * @return list<string>
     *
     * @throws InvalidCustomFile
     */
    public static function parse(Kind $kind, string $body): array
    {
        if (strlen($body) > $kind->maxBytes()) {
            throw new InvalidCustomFile("{$kind->value} must not be larger than {$kind->maxBytes()} bytes", 413);
        }
        if (!mb_check_encoding($body, 'UTF-8')) {
            throw new InvalidCustomFile('the file is not valid UTF-8');
        }

        $lines = [];
        $problems = [];
        foreach (preg_split('~\r\n|\n|\r~', $body) ?: [] as $index => $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            $problem = self::problem($kind, $line);
            if ($problem !== null) {
                $problems[] = 'line ' . ($index + 1) . ': ' . $problem;
                continue;
            }
            $lines[$line] = true;
        }

        if ($problems !== []) {
            $more = count($problems) > self::REPORTED ? ' (and ' . (count($problems) - self::REPORTED) . ' more)' : '';
            throw new InvalidCustomFile(implode('; ', array_slice($problems, 0, self::REPORTED)) . $more);
        }
        $lines = array_map(strval(...), array_keys($lines));
        if ($lines === []) {
            throw new InvalidCustomFile('the file has no usable line; to go back to the built-in lines reset it instead');
        }
        if (count($lines) > $kind->maxLines()) {
            throw new InvalidCustomFile("{$kind->value} must not have more than {$kind->maxLines()} lines", 413);
        }

        return $lines;
    }

    private static function problem(Kind $kind, string $line): ?string
    {
        if (mb_strlen($line) > $kind->maxLineLength()) {
            return "longer than {$kind->maxLineLength()} characters";
        }
        if ($kind === Kind::Headerlines) {
            // Header values: printable ASCII only. Anything else (control characters above all) could split the response.
            return preg_match('~^[\x20-\x7e]+$~', $line) === 1 ? null : 'only printable ASCII is allowed in a header line';
        }

        return preg_match('~[\x00-\x1f\x7f]~', $line) === 1 ? 'control characters are not allowed' : null;
    }

    /** @param list<string> $lines */
    public static function format(array $lines): string
    {
        return implode("\n", $lines) . "\n";
    }
}

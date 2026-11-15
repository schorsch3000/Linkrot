<?php

declare(strict_types=1);

namespace Linkrot;

use InvalidArgumentException;

/**
 * The tenants of this installation: one per hostname, listed in app_data/global-config.php:
 *
 *     <?php
 *     return ['hosts' => ['files.example.com', 'other.example.org']];
 *
 * The data of a tenant lives in app_data/<host>/. The Host header ends up in a path, so it is only ever used
 * after it matched an entry of this list, and the entries themselves are validated hostnames.
 */
final readonly class Tenants
{
    public const string FILE_NAME = 'global-config.php';

    /**
     * @param list<string> $hosts normalized hostnames
     * @param string|null $mailFrom address of the sender of the mails (codes for self-service tokens); null: the server sends no mail
     * @param string|null $mailName display name of the sender, if there is one
     */
    public function __construct(public array $hosts, public ?string $mailFrom = null, public ?string $mailName = null) {}

    /** What mail.from says, as it is written in the file: `address` or `Name <address>`. */
    public function mailSender(): ?string
    {
        return $this->mailFrom === null ? null : ($this->mailName === null ? $this->mailFrom : "{$this->mailName} <{$this->mailFrom}>");
    }

    /** A plain address (name@domain): no display name, no comma, nothing that could end up as a second header or recipient. */
    public static function isValidEmail(string $address): bool
    {
        return strlen($address) <= 254 && preg_match('~^[A-Za-z0-9._%+-]+@[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$~D', $address) === 1;
    }

    /** Lower-case hostname without port and trailing dot, or null if it is not a plain hostname (or IPv4 address). */
    public static function normalize(string $host): ?string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('~:\d{1,5}$~D', '', $host) ?? $host;
        $host = rtrim($host, '.');
        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        return preg_match('~^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$~D', $host) === 1 ? $host : null;
    }

    public static function load(string $file, bool $strict = false): self
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException("global config: file not found: $file");
        }
        $values = PhpFile::load($file, 'global config', $strict);
        if (!is_array($values)) {
            throw new InvalidArgumentException("global config: $file must return an array with a hosts list");
        }
        $unknown = array_diff(array_map(strval(...), array_keys($values)), ['hosts', 'mail']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('global config: unknown keys: ' . implode(', ', $unknown));
        }
        $raw = $values['hosts'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new InvalidArgumentException('global config: hosts must be a non-empty list of hostnames');
        }

        $hosts = [];
        foreach ($raw as $entry) {
            $host = is_string($entry) ? self::normalize($entry) : null;
            if ($host === null || $host !== $entry) {
                throw new InvalidArgumentException('global config: not a plain hostname: ' . (is_scalar($entry) ? (string) $entry : gettype($entry)));
            }
            if (in_array($host, $hosts, true)) {
                throw new InvalidArgumentException("global config: host listed twice: $host");
            }
            $hosts[] = $host;
        }

        [$from, $name] = self::mailFrom($values['mail'] ?? null);

        return new self($hosts, $from, $name);
    }

    /**
     * mail.from is an address, or `Name <address>`: the name is shown by mail programs as the sender.
     *
     * @return array{string|null, string|null} address and display name
     */
    private static function mailFrom(mixed $mail): array
    {
        if ($mail === null) {
            return [null, null];
        }
        if (!is_array($mail) || array_diff(array_map(strval(...), array_keys($mail)), ['from']) !== []) {
            throw new InvalidArgumentException("global config: mail must be an array with the key 'from'");
        }
        $from = $mail['from'] ?? null;
        if (is_string($from) && self::isValidEmail($from)) {
            return [$from, null];
        }
        // the name stays plain too: no quotes, angle brackets, backslashes or control characters, so it needs no escaping
        if (is_string($from) && preg_match('~^([^<>"\\\\\x00-\x1f\x7f]{1,64}?)\s*<([^<>\s]+)>$~Du', $from, $m) === 1 && self::isValidEmail($m[2]) && trim($m[1]) !== '') {
            return [$m[2], trim($m[1])];
        }

        throw new InvalidArgumentException("global config: mail.from must be an address like linkrot@example.com or Linkrot <linkrot@example.com>");
    }

    /** The configured host a Host header refers to, or null (answer 421). */
    public function match(string $hostHeader): ?string
    {
        $host = self::normalize($hostHeader);

        return $host !== null && in_array($host, $this->hosts, true) ? $host : null;
    }

    /** Appends a host to the file (created if missing). Returns false if it was already listed. */
    public static function enroll(string $file, string $host): bool
    {
        $normalized = self::normalize($host);
        if ($normalized === null) {
            throw new InvalidArgumentException("not a plain hostname: $host");
        }
        $current = is_file($file) ? self::load($file) : new self([]);
        $hosts = $current->hosts;
        if (in_array($normalized, $hosts, true)) {
            return false;
        }
        $hosts[] = $normalized;
        $mail = $current->mailFrom === null ? '' : '    \'mail\' => [\'from\' => ' . var_export($current->mailSender(), true) . "],\n";
        $php = "<?php\n\nreturn [\n    'hosts' => [\n" . implode('', array_map(static fn(string $h): string => "        '$h',\n", $hosts)) . "    ],\n" . $mail . "];\n";
        if (file_put_contents($file, $php, LOCK_EX) === false) {
            throw new InvalidArgumentException("cannot write $file");
        }

        return true;
    }

    /**
     * For the command line tools: the tenant to work on. An explicit host must be listed; without one it is the
     * only host, if there is only one.
     */
    public function select(?string $host): string
    {
        if ($host !== null && $host !== '') {
            return $this->match($host) ?? throw new InvalidArgumentException("host is not listed in global-config.php: $host");
        }
        if (count($this->hosts) === 1) {
            return $this->hosts[0];
        }

        throw new InvalidArgumentException('several hosts are configured, name one (LINKROT_HOST=<host>): ' . implode(', ', $this->hosts));
    }
}

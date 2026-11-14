<?php

declare(strict_types=1);

namespace Linkrot;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The tenants of this installation: one per hostname, listed in app_data/global-config.yaml:
 *
 *     hosts:
 *       - files.example.com
 *       - other.example.org
 *
 * The data of a tenant lives in app_data/<host>/. The Host header ends up in a path, so it is only ever used
 * after it matched an entry of this list, and the entries themselves are validated hostnames.
 */
final readonly class Tenants
{
    public const string FILE_NAME = 'global-config.yaml';

    /** @param list<string> $hosts normalized hostnames */
    public function __construct(public array $hosts) {}

    /** Lower-case hostname without port and trailing dot, or null if it is not a plain hostname (or IPv4 address). */
    public static function normalize(string $host): ?string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('~:\d{1,5}$~', '', $host) ?? $host;
        $host = rtrim($host, '.');
        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        return preg_match('~^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$~', $host) === 1 ? $host : null;
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException("global config: file not found: $file");
        }
        try {
            $values = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new InvalidArgumentException("global config: $file is not valid YAML: " . $e->getMessage(), 0, $e);
        }
        if (!is_array($values)) {
            throw new InvalidArgumentException("global config: $file must contain a mapping with a hosts list");
        }
        $unknown = array_diff(array_map(strval(...), array_keys($values)), ['hosts']);
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

        return new self($hosts);
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
        $hosts = is_file($file) ? self::load($file)->hosts : [];
        if (in_array($normalized, $hosts, true)) {
            return false;
        }
        $hosts[] = $normalized;
        if (file_put_contents($file, Yaml::dump(['hosts' => $hosts], 2, 2), LOCK_EX) === false) {
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
            return $this->match($host) ?? throw new InvalidArgumentException("host is not listed in global-config.yaml: $host");
        }
        if (count($this->hosts) === 1) {
            return $this->hosts[0];
        }

        throw new InvalidArgumentException('several hosts are configured, name one (LINKROT_HOST=<host>): ' . implode(', ', $this->hosts));
    }
}

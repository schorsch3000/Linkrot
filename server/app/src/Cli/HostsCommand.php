<?php

declare(strict_types=1);

namespace Linkrot\Cli;

use InvalidArgumentException;
use Linkrot\Config;
use Linkrot\Tenants;

/** Implementation of the `hosts` command line tool: the tenants of an installation. */
final readonly class HostsCommand
{
    public const int OK = 0;
    public const int FAILED = 1;
    public const int USAGE = 2;

    /**
     * @param resource $out
     * @param resource $err
     */
    public function __construct(private string $dataRoot, private mixed $out, private mixed $err) {}

    /** @param list<string> $args arguments without the program name */
    public function run(array $args): int
    {
        try {
            return match (true) {
                $args === [], $args === ['ls'] => $this->ls(),
                $args[0] === 'add' && count($args) >= 2 => $this->add($args[1], $this->email(array_slice($args, 2))),
                $args[0] === 'rewrite' && count($args) <= 2 => $this->rewrite($args[1] ?? null),
                default => $this->usage(),
            };
        } catch (InvalidArgumentException $e) {
            fwrite($this->err, 'hosts: ' . $e->getMessage() . "\n");

            return self::FAILED;
        }
    }

    /**
     * @param list<string> $rest
     *
     * @throws InvalidArgumentException
     */
    private function email(array $rest): ?string
    {
        if ($rest === []) {
            return null;
        }
        if (count($rest) === 2 && $rest[0] === '--email') {
            return $rest[1];
        }
        if (count($rest) === 1 && str_starts_with($rest[0], '--email=')) {
            return substr($rest[0], 8);
        }

        return throw new InvalidArgumentException('usage: hosts add <host> [--email <address>]');
    }

    private function file(): string
    {
        return $this->dataRoot . '/' . Tenants::FILE_NAME;
    }

    private function ls(): int
    {
        fwrite($this->out, implode("\n", Tenants::load($this->file())->hosts) . "\n");

        return self::OK;
    }

    /**
     * Enrolls the host, creates its folders and a default config.php (with the address, if there is one). Only the first
     * line on stdout, `enrolled <host>` or `already enrolled <host>`, is for scripts; the rest goes to stderr.
     */
    private function add(string $name, ?string $email): int
    {
        $host = Tenants::normalize($name) ?? throw new InvalidArgumentException("not a plain hostname: $name");
        if ($email !== null && !Tenants::isValidEmail($email)) {
            throw new InvalidArgumentException("not a plain address: $email");
        }
        if (!is_dir($this->dataRoot) && !mkdir($this->dataRoot, 0o755, true) && !is_dir($this->dataRoot)) {
            throw new InvalidArgumentException("cannot create {$this->dataRoot}");
        }
        $new = Tenants::enroll($this->file(), $host);
        fwrite($this->out, ($new ? 'enrolled ' : 'already enrolled ') . $host . "\n");

        // 711: nginx opens the files itself (X-Accel-Redirect), it needs search permission on the way down but no listing
        foreach (["{$this->dataRoot}/$host", "{$this->dataRoot}/$host/files"] as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0o711)) {
                    throw new InvalidArgumentException("cannot create $dir");
                }
                chmod($dir, 0o711); // whatever the umask says
            }
        }

        $config = "{$this->dataRoot}/$host/config.php";
        $baseUrl = Config::baseUrlFor($host);
        if (!is_file($config)) {
            HostConfigFile::write($config, $email === null ? [] : ['email' => $email], $baseUrl);
            fwrite($this->err, "hosts: wrote $config\n");
        } elseif ($email !== null) {
            HostConfigFile::write($config, ['email' => $email] + HostConfigFile::values($config, $baseUrl), $baseUrl);
            fwrite($this->err, "hosts: set email in $config (the old file is config.php.bak)\n");
        } else {
            fwrite($this->err, "hosts: $config exists, left alone (hosts rewrite $host regenerates it)\n");
        }

        return self::OK;
    }

    /** Writes config.php again from the values it sets, with all the other options as comments. One host, or all. */
    private function rewrite(?string $name): int
    {
        $tenants = Tenants::load($this->file());
        $hosts = $name === null ? $tenants->hosts : [$tenants->select($name)];
        $failed = false;
        foreach ($hosts as $host) {
            $config = "{$this->dataRoot}/$host/config.php";
            try {
                $changed = is_file($config)
                    ? HostConfigFile::rewrite($config, Config::baseUrlFor($host))
                    : HostConfigFile::write($config, [], Config::baseUrlFor($host));
                fwrite($this->out, ($changed ? 'rewritten ' : 'unchanged ') . "$host\n");
            } catch (InvalidArgumentException $e) {
                fwrite($this->err, "hosts: $host: " . $e->getMessage() . "\n");
                $failed = true;
            }
        }

        return $failed ? self::FAILED : self::OK;
    }

    private function usage(): int
    {
        fwrite($this->err, <<<'TXT'
            usage: hosts                                  list the hosts
                   hosts add <host> [--email <address>]   enroll a host, create its folders and config.php
                   hosts rewrite [<host>]                 write config.php again: set values kept, all other options
                                                          as commented defaults (one host, or all); the old file is
                                                          kept as config.php.bak

            TXT);

        return self::USAGE;
    }
}

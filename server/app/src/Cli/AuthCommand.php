<?php

declare(strict_types=1);

namespace Linkrot\Cli;

use InvalidArgumentException;
use Linkrot\Auth\TokenStore;

/** Implementation of the `lr-auth` command line tool. */
final readonly class AuthCommand
{
    public const int OK = 0;
    public const int FAILED = 1;
    public const int USAGE = 2;

    /**
     * @param resource $out
     * @param resource $err
     */
    public function __construct(private TokenStore $tokens, private mixed $out, private mixed $err) {}

    /** @param list<string> $args arguments without the program name */
    public function run(array $args): int
    {
        $command = $args[0] ?? '';

        return match (true) {
            $command === 'get' && count($args) === 2 => $this->get($args[1]),
            $command === 'del' && count($args) === 2 => $this->del($args[1]),
            $command === 'ls' && count($args) === 1 => $this->ls(),
            default => $this->usage(),
        };
    }

    private function get(string $name): int
    {
        try {
            $token = $this->tokens->create($name);
        } catch (InvalidArgumentException $e) {
            fwrite($this->err, 'lr-auth: ' . $e->getMessage() . "\n");

            return self::FAILED;
        }
        fwrite($this->out, $token . "\n");

        return self::OK;
    }

    private function del(string $name): int
    {
        if (!$this->tokens->delete($name)) {
            fwrite($this->err, "lr-auth: no token named '$name'\n");

            return self::FAILED;
        }

        return self::OK;
    }

    private function ls(): int
    {
        foreach ($this->tokens->list() as $token) {
            $used = $token['last_used_at'] === null ? 'never' : gmdate('Y-m-d H:i:s', $token['last_used_at']) . ' UTC';
            fwrite($this->out, $token['name'] . "\t" . $used . "\n");
        }

        return self::OK;
    }

    private function usage(): int
    {
        fwrite($this->err, <<<'TXT'
            usage: lr-auth get <name>   create a token for <name> and print it (shown only once)
                   lr-auth del <name>   delete the token of <name>
                   lr-auth ls           list tokens (name, last used)

            TXT);

        return self::USAGE;
    }
}

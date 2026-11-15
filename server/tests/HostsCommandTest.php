<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Cli\HostConfigFile;
use Linkrot\Cli\HostsCommand;
use Linkrot\Config;
use Linkrot\Tenants;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class HostsCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/linkrot-hosts-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $remove = static function (string $path) use (&$remove): void {
            if (is_dir($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        $remove($path . '/' . $entry);
                    }
                }
                rmdir($path);
            } elseif (file_exists($path)) {
                unlink($path);
            }
        };
        $remove($this->root);
    }

    /** @return array{int, string, string} */
    private function hosts(string ...$args): array
    {
        $out = fopen('php://memory', 'w+') ?: self::fail();
        $err = fopen('php://memory', 'w+') ?: self::fail();
        $code = new HostsCommand($this->root, $out, $err)->run(array_values($args));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    private function config(string $host = 'a.example'): string
    {
        return "{$this->root}/$host/config.php";
    }

    public function testAddEnrollsCreatesFoldersAndWritesADefaultConfig(): void
    {
        [$code, $out] = $this->hosts('add', 'A.Example');

        self::assertSame(0, $code);
        self::assertSame("enrolled a.example\n", $out);
        self::assertSame(['a.example'], Tenants::load($this->root . '/global-config.php')->hosts);
        self::assertDirectoryExists("{$this->root}/a.example/files");
        self::assertSame(0o711, fileperms("{$this->root}/a.example/files") & 0o777);
        self::assertSame([], HostConfigFile::values($this->config()));
        self::assertStringStartsWith("<?php\n", (string) file_get_contents($this->config()));
    }

    public function testAddWithEmailSetsItInTheConfig(): void
    {
        [$code] = $this->hosts('add', 'a.example', '--email', 'me@example.com');

        self::assertSame(0, $code);
        self::assertSame(['email' => 'me@example.com'], HostConfigFile::values($this->config()));
        self::assertSame(0, $this->hosts('add', 'b.example', '--email=you@example.com')[0]);
        self::assertSame(['email' => 'you@example.com'], HostConfigFile::values($this->config('b.example')));
    }

    public function testAddKeepsAnExistingConfigButSetsTheEmailIfGiven(): void
    {
        $this->hosts('add', 'a.example');
        file_put_contents($this->config(), "<?php return ['max_rm_after' => '30d'];\n");

        [$code, $out] = $this->hosts('add', 'a.example');
        self::assertSame([0, "already enrolled a.example\n"], [$code, $out]);
        self::assertSame(['max_rm_after' => '30d'], HostConfigFile::values($this->config()));

        $this->hosts('add', 'a.example', '--email', 'me@example.com');
        self::assertSame(['email' => 'me@example.com', 'max_rm_after' => '30d'], HostConfigFile::values($this->config()));
        self::assertFileExists($this->config() . '.bak');
    }

    public function testAddKeepsTheMailSettingsOfTheGlobalConfig(): void
    {
        mkdir($this->root);
        file_put_contents($this->root . '/global-config.php', "<?php return ['hosts' => ['x.example'], 'mail' => ['from' => 'lr@example.com']];\n");
        $this->hosts('add', 'a.example');

        self::assertSame('lr@example.com', Tenants::load($this->root . '/global-config.php')->mailFrom);
    }

    public function testAddRefusesBadInput(): void
    {
        self::assertSame(1, $this->hosts('add', '../etc')[0]);
        self::assertSame(1, $this->hosts('add', 'a.example', '--email', 'Me <me@example.com>')[0]);
        self::assertSame(1, $this->hosts('add', 'a.example', '--mail', 'x')[0]);
        self::assertFileDoesNotExist($this->root . '/global-config.php');
    }

    public function testRewriteKeepsValuesAndCommentsOutTheRest(): void
    {
        $this->hosts('add', 'a.example');
        file_put_contents($this->config(), "<?php\n// my note\nreturn ['quota' => '100gb', 'default_uses' => 3, 'email' => 'Me@Example.com'];\n");

        [$code, $out] = $this->hosts('rewrite', 'a.example');

        self::assertSame([0, "rewritten a.example\n"], [$code, $out]);
        self::assertSame(['email' => 'Me@Example.com', 'default_uses' => 3, 'quota' => '100gb'], HostConfigFile::values($this->config()));
        $text = (string) file_get_contents($this->config());
        self::assertStringContainsString("    'quota' => '100gb',", $text);
        self::assertStringContainsString("    // 'max_rm_after' => '1y',", $text);
        self::assertStringContainsString("    // 'default_uses' => null,", $text . "    // 'default_uses' => null,", 'unset stays commented');
        self::assertStringNotContainsString('my note', $text);
        self::assertStringContainsString('my note', (string) file_get_contents($this->config() . '.bak'));
    }

    public function testRewriteIsIdempotent(): void
    {
        $this->hosts('add', 'a.example', '--email', 'me@example.com');
        $this->hosts('rewrite', 'a.example');
        $first = (string) file_get_contents($this->config());

        self::assertSame([0, "unchanged a.example\n"], array_slice($this->hosts('rewrite', 'a.example'), 0, 2));
        self::assertSame($first, (string) file_get_contents($this->config()));
    }

    public function testRewriteWithoutAHostDoesAllOfThemAndStopsAtNothing(): void
    {
        $this->hosts('add', 'a.example');
        $this->hosts('add', 'b.example');
        file_put_contents($this->config('b.example'), "<?php return ['no_such_option' => 1];\n");
        file_put_contents($this->config(), "<?php return ['quota' => 5];\n");

        [$code, $out, $err] = $this->hosts('rewrite');

        self::assertSame(1, $code, 'one host failed');
        self::assertSame("rewritten a.example\n", $out);
        self::assertStringContainsString('b.example', $err);
        self::assertSame("<?php return ['no_such_option' => 1];\n", file_get_contents($this->config('b.example')), 'a broken config is not touched');
    }

    public function testRewriteOfAnUnknownHostFails(): void
    {
        $this->hosts('add', 'a.example');
        self::assertSame(1, $this->hosts('rewrite', 'other.example')[0]);
    }

    public function testListAndUsage(): void
    {
        $this->hosts('add', 'a.example');
        self::assertSame([0, "a.example\n"], array_slice($this->hosts(), 0, 2));
        self::assertSame([0, "a.example\n"], array_slice($this->hosts('ls'), 0, 2));
        self::assertSame(2, $this->hosts('frobnicate')[0]);
    }

    public function testTheGeneratedFileSaysLoudlyThatCommentsAreNotKept(): void
    {
        $text = HostConfigFile::render([]);

        self::assertStringStartsWith("<?php\n", $text);
        self::assertStringContainsString('THIS FILE IS GENERATED', $text);
        self::assertStringContainsString('EVERYTHING THAT IS ONLY A COMMENT MIGHT BE GONE', $text);
    }

    public function testEveryOptionIsDocumentedAndEveryDefaultIsRight(): void
    {
        $params = array_map(
            static fn(\ReflectionParameter $p): string => strtolower((string) preg_replace('~[A-Z]~', '_$0', $p->getName())),
            new ReflectionClass(Config::class)->getConstructor()?->getParameters() ?? [],
        );
        $options = array_values(array_diff($params, ['base_url']));
        $documented = array_keys(HostConfigFile::DOC);
        $defaulted = array_keys(HostConfigFile::defaults());
        sort($options);
        sort($documented);
        sort($defaulted);
        self::assertSame($options, $documented, 'a new option needs a line in HostConfigFile::DOC');
        self::assertSame($documented, $defaulted);

        // uncommenting everything gives the defaults back
        $uncommented = (string) preg_replace('~^    // (\'\w+\' =>)~m', '    $1', HostConfigFile::render([]));
        $file = sys_get_temp_dir() . '/linkrot-uncommented-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, $uncommented);
        try {
            $values = HostConfigFile::values($file);
        } finally {
            unlink($file);
        }
        self::assertSame(HostConfigFile::defaults(), $values);
        self::assertEquals(new Config('https://localhost'), Config::fromArray(array_filter($values, static fn(mixed $v): bool => $v !== null), 'https://localhost'));
    }
}

<?php

declare(strict_types=1);

namespace Linkrot\Setup;

use Linkrot\Tenants;
use PDO;
use Throwable;

/**
 * What the setup finds out on the server side: the requirements, where the data can live, which journal the
 * databases can have, and linkrot-paths.php, which tells index.php where code and data are.
 */
final readonly class SetupEnvironment
{
    public const string PATHS_FILE = 'linkrot-paths.php';
    public const string DATA_NAME = 'linkrot-data';
    public const string PROBE_FILE = 'setup-probe.bin';

    /** File systems on which WAL works: shared memory next to the database file. */
    private const array LOCAL_FILE_SYSTEMS = ['ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'zfs', 'tmpfs', 'overlay', 'f2fs', 'reiserfs', 'jfs', 'apfs', 'ufs'];

    /**
     * @param string $root the folder of setup.php and index.php
     * @param string $documentRoot DOCUMENT_ROOT of the web server ($root if it does not say)
     */
    public function __construct(public string $root, public string $documentRoot) {}

    /** @return array<string, mixed> what linkrot-paths.php says, [] if there is none */
    public function paths(): array
    {
        $file = $this->root . '/' . self::PATHS_FILE;
        if (!is_file($file)) {
            return [];
        }
        $paths = (static fn(string $file): mixed => require $file)($file);

        return is_array($paths) ? array_filter($paths, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }

    public function appDir(): string
    {
        $paths = $this->paths();

        return is_string($paths['app'] ?? null) ? $paths['app'] : $this->root . '/linkrot-app';
    }

    public function dataDir(): ?string
    {
        $paths = $this->paths();

        return is_string($paths['data'] ?? null) ? $paths['data'] : null;
    }

    /** Whether a folder is below the document root, so that the web server would hand out its files without the .htaccess. */
    public function isPublic(string $dir): bool
    {
        $root = realpath($this->documentRoot);
        $real = realpath($dir);

        return $root !== false && $real !== false && ($real === $root || str_starts_with($real . '/', rtrim($root, '/') . '/'));
    }

    /** The URL path of a folder below the document root, null for one outside. */
    public function urlPath(string $dir): ?string
    {
        if (!$this->isPublic($dir)) {
            return null;
        }
        $relative = substr((string) realpath($dir), strlen(rtrim((string) realpath($this->documentRoot), '/')));

        return implode('/', array_map(rawurlencode(...), explode('/', $relative)));
    }

    /**
     * Finds a home for the data and writes linkrot-paths.php: next to the document root if PHP may create a folder
     * there (nothing the web server could hand out, open_basedir permitting), otherwise below it, locked with a
     * .htaccess (the probes of the setup check that it works). An existing linkrot-data next to the document root is
     * taken as it is: the data of an earlier installation of this webspace.
     *
     * @return array{string, bool} the folder, and whether it is outside the document root
     */
    public function placeData(): array
    {
        $existing = $this->dataDir();
        if ($existing !== null && is_dir($existing)) {
            return [$existing, !$this->isPublic($existing)];
        }
        $outside = dirname(realpath($this->documentRoot) ?: $this->documentRoot) . '/' . self::DATA_NAME;
        if ($this->usable($outside) && !$this->isPublic($outside)) {
            $this->writePaths($outside);

            return [$outside, true];
        }
        $inside = $this->root . '/' . self::DATA_NAME;
        if (!$this->usable($inside)) {
            throw new \RuntimeException("cannot create a folder for the data, neither $outside nor $inside: PHP needs write permission in one of them");
        }
        $this->lock($inside);
        $this->writePaths($inside);

        return [$inside, false];
    }

    private function usable(string $dir): bool
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0o711) && !is_dir($dir)) {
                return false;
            }
            // whatever the umask of the web server says: the way down to the files, no listing (as for the hosts)
            @chmod($dir, 0o711);
        }
        $probe = $dir . '/.linkrot-write-test';
        if (@file_put_contents($probe, 'x') !== 1) {
            return false;
        }
        @unlink($probe);

        return true;
    }

    /** A .htaccess that denies everything (Apache 2.2 and 2.4, LiteSpeed), and an empty index against listings. */
    public function lock(string $dir): void
    {
        $htaccess = <<<'HTACCESS'
            # linkrot: nothing in here is for the web; the downloads go through index.php.
            <IfModule mod_authz_core.c>
                Require all denied
            </IfModule>
            <IfModule !mod_authz_core.c>
                Order deny,allow
                Deny from all
            </IfModule>

            HTACCESS;
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', $htaccess);
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
    }

    private function writePaths(string $data): void
    {
        $php = "<?php\n\n// Written by setup.php: where the code and the data of this installation are. The first request after an\n"
            . "// upgrade (new files over the old ones) migrates the databases (auto_migrate).\nreturn [\n"
            . '    \'app\' => ' . var_export($this->appDir(), true) . ",\n"
            . '    \'data\' => ' . var_export($data, true) . ",\n"
            . "    'auto_migrate' => true,\n];\n";
        $file = $this->root . '/' . self::PATHS_FILE;
        if (@file_put_contents($file . '.tmp', $php) === false || !@rename($file . '.tmp', $file)) {
            throw new \RuntimeException("cannot write $file");
        }
    }

    /** A file with random content in the data folder: the probes check that the web cannot fetch it, and send it. */
    public function probeFile(string $data): string
    {
        $file = $data . '/' . self::PROBE_FILE;
        if (!is_file($file) || filesize($file) !== 32) {
            file_put_contents($file, bin2hex(random_bytes(16)));
        }

        return $file;
    }

    /**
     * The journal the databases can have in this folder: WAL on a local file system, DELETE on a network file system
     * and whenever it cannot be told (or WAL does not work at all).
     *
     * @return array{string, string} journal and why
     */
    public function journal(string $dir): array
    {
        $type = $this->fileSystem($dir);
        if ($type === null) {
            return [Tenants::JOURNAL_DELETE, 'the file system of the data folder is unknown (no /proc/mounts), the classic journal is the safe choice'];
        }
        if (!in_array($type, self::LOCAL_FILE_SYSTEMS, true)) {
            return [Tenants::JOURNAL_DELETE, "the data folder is on $type, WAL is not safe there"];
        }
        $test = $dir . '/.linkrot-wal-test.sqlite';
        try {
            $pdo = new PDO('sqlite:' . $test, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $mode = \Linkrot\Database::query($pdo, 'PRAGMA journal_mode = WAL')->fetchColumn();
            $pdo->exec('CREATE TABLE t (x)');
            unset($pdo);
        } catch (Throwable) {
            $mode = false;
        } finally {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($test . $suffix);
            }
        }

        return $mode === 'wal'
            ? [Tenants::JOURNAL_WAL, "the data folder is on $type"]
            : [Tenants::JOURNAL_DELETE, "WAL does not work in the data folder ($type)"];
    }

    /** The type of the file system a folder is on, from /proc/mounts (the longest mount point that contains it). */
    private function fileSystem(string $dir): ?string
    {
        $real = realpath($dir);
        $mounts = @file_get_contents('/proc/mounts');
        if ($real === false || $mounts === false) {
            return null;
        }
        $best = null;
        $length = -1;
        foreach (explode("\n", $mounts) as $line) {
            $fields = explode(' ', $line);
            if (count($fields) < 3) {
                continue;
            }
            $point = str_replace('\040', ' ', $fields[1]);
            if (($real === $point || str_starts_with($real, rtrim($point, '/') . '/')) && strlen($point) > $length) {
                $best = $fields[2];
                $length = strlen($point);
            }
        }

        return $best;
    }

    /**
     * The requirements, each with a level (ok, warn, error) and what it means.
     *
     * @return list<array{name: string, level: string, message: string}>
     */
    public function requirements(): array
    {
        $checks = [];
        $add = static function (string $name, string $level, string $message) use (&$checks): void {
            $checks[] = ['name' => $name, 'level' => $level, 'message' => $message];
        };
        // older versions do not get this far: setup.php checks the version before it loads any of the code
        $add('php', 'ok', 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')');
        foreach (['pdo_sqlite' => 'the databases', 'mbstring' => 'text', 'ctype' => 'names', 'iconv' => 'the QR code', 'xmlwriter' => 'the QR code', 'hash' => 'checksums', 'json' => 'the API'] as $extension => $for) {
            $loaded = extension_loaded($extension);
            $add("ext-$extension", $loaded ? 'ok' : 'error', $loaded ? "for $for" : "missing, needed for $for: switch it on in the PHP settings of the hoster");
        }
        $apcu = function_exists('apcu_enabled') && apcu_enabled();
        $add('ext-apcu', $apcu ? 'ok' : 'warn', $apcu ? 'counts failed logins in memory' : 'not there: failed logins are counted in a SQLite file instead, which is fine');
        $disabled = array_map(trim(...), explode(',', (string) ini_get('disable_functions')));
        foreach (['fsync' => 'uploads are written to disk safely', 'flock' => 'two requests never write the same file', 'set_time_limit' => 'long downloads are not cut off', 'mail' => 'tokens by mail'] as $function => $for) {
            $there = function_exists($function) && !in_array($function, $disabled, true);
            $level = $there ? 'ok' : ($function === 'mail' || $function === 'set_time_limit' ? 'warn' : 'error');
            $add("$function()", $level, $there ? $for : "disabled by the hoster: $for does not work");
        }
        $basedir = (string) ini_get('open_basedir');
        $add('open_basedir', 'ok', $basedir === '' ? 'no restriction' : "PHP may only use $basedir");
        $finish = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
        $add('cleanup after the answer', $finish ? 'ok' : 'warn', $finish ? 'the client does not wait for the cleanup' : 'now and then a request waits for the cleanup of expired files (no php-fpm or LiteSpeed)');
        $memory = ini_get('memory_limit');
        $add('memory_limit', 'ok', $memory);

        return $checks;
    }
}

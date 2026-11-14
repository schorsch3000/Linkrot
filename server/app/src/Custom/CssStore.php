<?php

declare(strict_types=1);

namespace Linkrot\Custom;

use Closure;
use RuntimeException;

/**
 * The stylesheet of a host, in app_data/<host>/custom/: `user.<hash>.css` (hash: the first 16 hex of the sha256 of the
 * content, so the URL changes with the content and can be cached forever) and `css.json`, which says which file is
 * current and which ones were retired when.
 *
 * A replaced or reset stylesheet is retired, not deleted: a page that was loaded a moment ago still asks for the old
 * URL. The cleanup removes retired files after GRACE seconds; at most MAX_RETIRED are kept, the oldest go first.
 */
final readonly class CssStore
{
    public const int MAX_BYTES = 64 * 1024;
    public const int GRACE = 86400;
    public const int MAX_RETIRED = 5;
    public const string URL_PREFIX = '/assets/';

    private const string NAME_PATTERN = '~^user\.[0-9a-f]{16}\.css$~';

    /** @param Closure(): int $clock */
    public function __construct(private string $dir, private Closure $clock) {}

    /** True for a file name that can be a stylesheet of a host: the only names the route serves. */
    public static function isFileName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    public function has(): bool
    {
        return $this->state()['current'] !== null;
    }

    /** URL of the current stylesheet for the `<link>` of the pages, or null if the host has none. */
    public function href(): ?string
    {
        $current = $this->state()['current'];

        return $current === null ? null : self::URL_PREFIX . $this->fileName($current);
    }

    /** The current stylesheet, or the starter file as long as the host did not upload one. */
    public function text(): string
    {
        $current = $this->state()['current'];
        $css = $current === null ? false : @file_get_contents($this->path($this->fileName($current)));

        return $css === false ? self::starter() : $css;
    }

    /** The content of a stylesheet that is current or still retired, by its file name; null if there is none. */
    public function read(string $fileName): ?string
    {
        if (!self::isFileName($fileName)) {
            return null;
        }
        $css = @file_get_contents($this->path($fileName));

        return $css === false ? null : $css;
    }

    /**
     * @throws InvalidCustomFile
     */
    public function put(string $body): string
    {
        if (strlen($body) > self::MAX_BYTES) {
            throw new InvalidCustomFile('css must not be larger than ' . self::MAX_BYTES . ' bytes', 413);
        }
        if (!mb_check_encoding($body, 'UTF-8')) {
            throw new InvalidCustomFile('the file is not valid UTF-8');
        }
        if (str_contains($body, "\0")) {
            throw new InvalidCustomFile('the file contains a NUL byte');
        }
        if (trim($body) === '') {
            throw new InvalidCustomFile('the file is empty; to go back to the built-in look reset it instead');
        }
        $hash = substr(hash('sha256', $body), 0, 16);

        return $this->locked(function () use ($body, $hash): string {
            $state = $this->state();
            if ($state['current'] === $hash) {
                return $hash;
            }
            $file = $this->path($this->fileName($hash));
            if (!is_file($file)) {
                $this->writeAtomic($file, $body);
            }
            // an old stylesheet that is uploaded again is current again, not retired as well
            $state['retired'] = array_values(array_filter($state['retired'], static fn(array $r): bool => $r['hash'] !== $hash));
            $this->retire($state);
            $state['current'] = $hash;
            $this->save($state);

            return $hash;
        });
    }

    public function reset(): void
    {
        $this->locked(function (): void {
            $state = $this->state();
            if ($state['current'] === null) {
                return;
            }
            $this->retire($state);
            $state['current'] = null;
            $this->save($state);
        });
    }

    /**
     * Removes the retired stylesheets whose grace period is over.
     *
     * @return array{int, int} files removed, files that could not be removed
     */
    public function removeExpired(): array
    {
        if (!is_file($this->path('css.json'))) {
            return [0, 0];
        }

        return $this->locked(function (): array {
            $now = ($this->clock)();
            $state = $this->state();
            $removed = 0;
            $failed = 0;
            $kept = [];
            foreach ($state['retired'] as $entry) {
                if ($entry['at'] + self::GRACE > $now) {
                    $kept[] = $entry;
                    continue;
                }
                $path = $this->path($this->fileName($entry['hash']));
                if (@unlink($path) || !file_exists($path)) {
                    ++$removed;
                } else {
                    ++$failed;
                    $kept[] = $entry;
                }
            }
            if ($kept !== $state['retired']) {
                $state['retired'] = $kept;
                $this->save($state);
            }

            return [$removed, $failed];
        });
    }

    /**
     * The file a host gets before it uploaded one: the colors of the built-in stylesheet. The user stylesheet is loaded
     * after it, so it only has to name what it changes.
     */
    public static function starter(): string
    {
        return <<<'CSS'
            /*
             * Your colors for the pages of this server. This file is loaded after the built-in stylesheet, so it only
             * needs what you change; delete the lines you are happy with.
             *
             *   lr custom put css user.css      use this file
             *   lr custom reset css             back to the built-in look
             *
             * The first block is the light scheme, the second one the dark scheme (used when the visitor's system is
             * dark). To always use one scheme: delete the other block and set color-scheme to light or dark.
             *
             * The pages you upload with `lr page put` have <body class="custompage custompage_<name>">, so you can style
             * all of them (.custompage) or just one (.custompage_imprint).
             */
            :root {
              color-scheme: light dark;
              --bg: #f3f4ef;        /* page background */
              --card: #fff;         /* the card and the QR dialog */
              --fg: #1c211d;        /* text */
              --muted: #646f66;     /* secondary text, the chain logo */
              --line: #dde1d8;      /* borders */
              --accent: #3d7a4b;    /* the download button, the copy confirmation */
              --accent-fg: #fff;    /* text on the accent */
              --code-bg: #f0f2ec;   /* the boxes with the commands */
              --rot-a: #5a9a3c;     /* "rot" in the headline: from this color ... */
              --rot-b: #a07a2a;     /* ... to this one, also the focus ring */
            }
            @media (prefers-color-scheme: dark) {
              :root {
                --bg: #111411;
                --card: #1a1f1b;
                --fg: #e6ebe7;
                --muted: #94a096;
                --line: #2b332d;
                --accent: #7dbd8b;
                --accent-fg: #0e1a11;
                --code-bg: #131813;
                --rot-a: #8ccf5f;
                --rot-b: #c9a14a;
              }
            }

            CSS;
    }

    /** @param array{current: ?string, retired: list<array{hash: string, at: int}>} $state */
    private function retire(array &$state): void
    {
        if ($state['current'] !== null) {
            $state['retired'][] = ['hash' => $state['current'], 'at' => ($this->clock)()];
        }
        while (count($state['retired']) > self::MAX_RETIRED) {
            $oldest = array_shift($state['retired']);
            @unlink($this->path($this->fileName($oldest['hash'])));
        }
    }

    /** @return array{current: ?string, retired: list<array{hash: string, at: int}>} */
    private function state(): array
    {
        $empty = ['current' => null, 'retired' => []];
        $raw = @file_get_contents($this->path('css.json'));
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return $empty;
        }
        $isHash = static fn(mixed $h): bool => is_string($h) && preg_match('~^[0-9a-f]{16}$~', $h) === 1;
        $retired = [];
        foreach (is_array($data['retired'] ?? null) ? $data['retired'] : [] as $entry) {
            if (is_array($entry) && $isHash($entry['hash'] ?? null) && is_int($entry['at'] ?? null)) {
                $retired[] = ['hash' => $entry['hash'], 'at' => $entry['at']];
            }
        }

        return ['current' => $isHash($data['current'] ?? null) ? $data['current'] : null, 'retired' => $retired];
    }

    /** @param array{current: ?string, retired: list<array{hash: string, at: int}>} $state */
    private function save(array $state): void
    {
        $this->writeAtomic($this->path('css.json'), json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function fileName(string $hash): string
    {
        return "user.$hash.css";
    }

    private function path(string $name): string
    {
        return $this->dir . '/' . $name;
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function locked(Closure $work): mixed
    {
        $this->ensureDir();
        $lock = fopen($this->path('css.lock'), 'c') ?: throw new RuntimeException('cannot open the css lock');
        flock($lock, LOCK_EX);
        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('cannot create ' . $this->dir);
        }
    }

    private function writeAtomic(string $path, string $content): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $content) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("cannot write $path");
        }
    }
}

<?php

declare(strict_types=1);

namespace Linkrot\Pages;

use Closure;
use Linkrot\Custom\InvalidCustomFile;
use RuntimeException;

/**
 * The static pages of a host, in app_data/<host>/pages/:
 *
 *   <slug>.md      exactly what was uploaded; `get` gives it back unchanged
 *   <slug>.html    the rendered body, made at upload; the public page and the footer only ever read this
 *   index.json     title, link, order, ... of every page, so that the footer of every page is one small file
 *
 * The .md files are the truth. The HTML and the index are a cache: if the index is missing, damaged or was made by an
 * older PageRenderer, everything is rendered again from the sources.
 */
final readonly class PageStore
{
    public const int MAX_PAGES = 20;
    public const int MAX_BYTES = 64 * 1024;

    /** @param Closure(): int $clock */
    public function __construct(private string $dir, private PageRenderer $renderer, private Closure $clock) {}

    public static function isSlug(string $slug): bool
    {
        return preg_match('~^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$~', $slug) === 1;
    }

    /**
     * All pages, in the order of the footer: `order`, then link text, then slug.
     *
     * @return list<PageMeta>
     */
    public function all(): array
    {
        $pages = array_values($this->index());
        usort($pages, static fn(PageMeta $a, PageMeta $b): int => [$a->order, mb_strtolower($a->link), $a->slug] <=> [$b->order, mb_strtolower($b->link), $b->slug]);

        return $pages;
    }

    /** @return list<PageMeta> */
    public function published(): array
    {
        return array_values(array_filter($this->all(), static fn(PageMeta $page): bool => $page->published));
    }

    public function meta(string $slug): ?PageMeta
    {
        return $this->index()[$slug] ?? null;
    }

    /** The upload, byte for byte. */
    public function source(string $slug): ?string
    {
        if (!self::isSlug($slug)) {
            return null;
        }
        $source = @file_get_contents($this->path($slug, 'md'));

        return $source === false ? null : $source;
    }

    /** The rendered body (an HTML fragment) of a page, published or not. */
    public function html(string $slug): ?string
    {
        if (!self::isSlug($slug) || !$this->meta($slug) instanceof \Linkrot\Pages\PageMeta) {
            return null;
        }
        $html = @file_get_contents($this->path($slug, 'html'));

        return $html === false ? null : $html;
    }

    /**
     * @throws InvalidCustomFile
     */
    public function put(string $slug, string $source): PageMeta
    {
        if (!self::isSlug($slug)) {
            throw new InvalidCustomFile('the name of a page has 1 to 40 characters: a-z, 0-9 and -, not at the start or end');
        }
        if (strlen($source) > self::MAX_BYTES) {
            throw new InvalidCustomFile('a page must not be larger than ' . self::MAX_BYTES . ' bytes', 413);
        }
        if (!mb_check_encoding($source, 'UTF-8')) {
            throw new InvalidCustomFile('the file is not valid UTF-8');
        }
        if (str_contains($source, "\0")) {
            throw new InvalidCustomFile('the file contains a NUL byte');
        }
        [$meta, $html] = $this->make($slug, $source);

        return $this->locked(function () use ($slug, $source, $meta, $html): PageMeta {
            $index = $this->currentIndex();
            if (!isset($index[$slug]) && count($index) >= self::MAX_PAGES) {
                throw new InvalidCustomFile('at most ' . self::MAX_PAGES . ' pages are possible, delete one first');
            }
            $this->writeAtomic($this->path($slug, 'html'), $html);
            $this->writeAtomic($this->path($slug, 'md'), $source);
            $index[$slug] = $meta;
            $this->saveIndex($index);

            return $meta;
        });
    }

    public function delete(string $slug): void
    {
        if (!self::isSlug($slug)) {
            return;
        }
        $this->locked(function () use ($slug): void {
            $index = $this->currentIndex();
            foreach (['md', 'html'] as $extension) {
                $path = $this->path($slug, $extension);
                if (is_file($path) && !@unlink($path)) {
                    throw new RuntimeException("cannot remove $path");
                }
            }
            if (isset($index[$slug])) {
                unset($index[$slug]);
                $this->saveIndex($index);
            }
        });
    }

    /**
     * What `lr page template` gives: every key of the frontmatter and some content to start from. Unpublished, so that
     * uploading it unchanged does not put the dummy text into the footer of every page.
     */
    public static function template(): string
    {
        return <<<'MD'
            ---
            # Everything here is optional. The name of the page in its URL (/page/<name>) is not set here but when
            # uploading: lr page put imprint.md  ->  /page/imprint
            #
            # The headline of the page and the title of the browser tab.
            title: Imprint
            # The text of the link in the footer of every page. Default: the title.
            link: Imprint
            # The links are sorted by this number, lowest first (default 100), then by their text.
            order: 10
            # false: the page is not reachable and not linked (a draft). Change it to true when you are done.
            published: false
            # The language of the page: en, de, de-AT, ...
            lang: en
            ---

            Replace this text with your own. The page is written in Markdown; raw HTML is not allowed and is refused
            when you upload.

            ## What works

            Paragraphs, **bold**, *italic*, ~~strikethrough~~ and `code`, and links like
            [the linkrot project](https://github.com/schorsch3000/Linkrot) or an address such as
            <mailto:me@example.org>.

            - lists
            - with several items
              - and nested ones

            1. numbered lists
            2. too

            > A quote.

            | Name       | Value        |
            | ---------- | ------------ |
            | Tables     | work as well |
            | Alignment  | with colons  |

            Images come from an `https` address or are included in the text as `data:` (png, gif, jpeg, webp):

            ```
            ![alt text](https://example.org/logo.png)
            ```

            Headings of level 1 become level 2, because the title above is the headline of the page.

            ```
            lr page put imprint.md
            ```

            MD;
    }

    /**
     * @return array{PageMeta, string} the index entry and the rendered body
     *
     * @throws InvalidCustomFile
     */
    private function make(string $slug, string $source): array
    {
        [$values, $markdown] = Frontmatter::parse($source);
        $html = $this->renderer->render($markdown);
        $title = $values['title'] ?? $slug;
        $link = $values['link'] ?? (mb_strlen($title) > Frontmatter::MAX_LINK ? mb_substr($title, 0, Frontmatter::MAX_LINK) : $title);

        return [new PageMeta(
            $slug,
            $title,
            $link,
            $values['order'],
            $values['published'],
            $values['lang'],
            PageRenderer::VERSION,
            ($this->clock)(),
        ), $html];
    }

    /** @return array<string, PageMeta> by slug */
    private function index(): array
    {
        $index = $this->readIndex();
        if ($index !== null) {
            return $index;
        }
        // missing, damaged or from an older renderer: render again from the sources (nothing to do without a folder)
        if (!is_dir($this->dir)) {
            return [];
        }

        return $this->locked($this->currentIndex(...));
    }

    /**
     * For code that holds the lock already (a second lock on the same file from the same process would wait forever).
     *
     * @return array<string, PageMeta>
     */
    private function currentIndex(): array
    {
        return $this->readIndex() ?? $this->rebuild();
    }

    /** @return array<string, PageMeta>|null null if the index must be made again */
    private function readIndex(): ?array
    {
        $raw = @file_get_contents($this->path('index', 'json'));
        if ($raw === false) {
            return is_dir($this->dir) ? null : [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['pages'] ?? null)) {
            return null;
        }
        $index = [];
        foreach ($data['pages'] as $entry) {
            $meta = is_array($entry) ? PageMeta::fromArray($entry) : null;
            if (!$meta instanceof \Linkrot\Pages\PageMeta || $meta->renderVersion !== PageRenderer::VERSION || !self::isSlug($meta->slug)) {
                return null;
            }
            $index[$meta->slug] = $meta;
        }

        return $index;
    }

    /** @return array<string, PageMeta> */
    private function rebuild(): array
    {
        $index = [];
        foreach (glob($this->dir . '/*.md') ?: [] as $file) {
            $slug = basename($file, '.md');
            $source = @file_get_contents($file);
            if (!self::isSlug($slug) || $source === false) {
                continue;
            }
            try {
                [$meta, $html] = $this->make($slug, $source);
            } catch (InvalidCustomFile) {
                // the rules got stricter than when it was uploaded: it stays downloadable and listed, but is not shown
                $meta = new PageMeta($slug, $slug, $slug, 100, false, 'en', PageRenderer::VERSION, ($this->clock)());
                $html = '';
            }
            $this->writeAtomic($this->path($slug, 'html'), $html);
            $index[$slug] = $meta;
        }
        $this->saveIndex($index);

        return $index;
    }

    /** @param array<string, PageMeta> $index */
    private function saveIndex(array $index): void
    {
        $pages = array_map(static fn(PageMeta $meta): array => $meta->toArray(), array_values($index));
        $this->writeAtomic($this->path('index', 'json'), json_encode(['pages' => $pages], JSON_THROW_ON_ERROR));
    }

    private function path(string $name, string $extension): string
    {
        return $this->dir . '/' . $name . '.' . $extension;
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
        $lock = fopen($this->dir . '/pages.lock', 'c') ?: throw new RuntimeException('cannot open the pages lock');
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

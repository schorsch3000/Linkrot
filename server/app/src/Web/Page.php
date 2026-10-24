<?php

declare(strict_types=1);

namespace Linkrot\Web;

use Linkrot\FileName;
use Linkrot\Files\FileRecord;

/** HTML of the public pages: plain, no JavaScript, no external resources, light and dark. */
final class Page
{
    /** Never mention a date or a number of downloads here: the pages must not reveal when a link dies. */
    private const array TAGLINES = [
        'A link, but it\'s rotting.',
        'Fresh today. Compost tomorrow.',
        'Best before: soon.',
        'Handle with care, this link is not getting any fresher.',
        'Smells fine for now.',
        'Aging like milk.',
        'Please consume before it goes off.',
        'Shared fresh. Rotting already.',
        'Mold is just patience with spores.',
        'Download it before the mold does.',
        'Keep refrigerated. Or don\'t, it rots either way.',
        'Link rot: not a bug, a lifestyle.',
        'Contains traces of mold.',
        'Good things come to those who download early.',
        'Nothing gold can stay. This link, even less.',
        'Decomposing gracefully since the first click.',
        'Some links age like wine. This one ages like bananas.',
        'Brought to you by entropy.',
        'Entropy always wins.',
        'Slightly fermented.',
        'Do not leave in a warm browser tab.',
        'Fresh from the terminal, going stale at the speed of the internet.',
        'The mold is patient. You should not be.',
        'Organic, free-range, short-lived.',
        'Made from freshly recycled bytes.',
        'Tick, tock, mold.',
        'It was a good link. It lived a short, full life.',
        'Please do not bookmark me. I won\'t last.',
        'Bookmarking this is a bold move.',
        'Please do not feed the mold.',
        'Compost in progress.',
        'Spores included at no extra charge.',
        'Your friendly neighbourhood decaying link.',
        'Rot happens.',
        'A link with a short shelf life.',
        'Shelf life: unclear. Mold: certain.',
        'Handle gently. It bruises easily.',
        'Freshness not guaranteed.',
        'Download now, regret less later.',
        'Not every link is meant to be forever.',
        'Everything is temporary, including this link.',
        'Dust to dust, link to not-found.',
        'Born fresh, raised on bandwidth, destined for the compost heap.',
        'Gone before you know it, like a good sandwich.',
        'No preservatives added.',
        'Preservative-free since forever.',
        'Mind the mold.',
        'It\'s not rotten yet. Probably.',
        'Slowly turning into fertilizer.',
        'Smells a bit funky. Still fine.',
        'Fungi are already moving in.',
        'A fine specimen of impermanence.',
        'Rotting with dignity.',
        'Grab it while it\'s fresh.',
        'Please enjoy before the mold arrives.',
        'Link rot, but make it intentional.',
        'The mold got here first. Barely.',
        'Served fresh, rotting on schedule.',
        'We keep the mold, you keep the file.',
        'This link is in its ripe phase.',
        'Ripe now, overripe soon.',
        'Peak freshness. It\'s all downhill from here.',
        'Fermenting quietly in a corner of the internet.',
        'Slightly moist, entirely temporary.',
        'Warm, soft and slowly dissolving.',
        'Some assembly required. Some decay guaranteed.',
        'Please keep away from damp servers.',
        'Handle with tongs, it\'s getting fuzzy.',
        'Marinating in bits and bytes.',
        'Left out on the counter overnight.',
        'Its best moment is right now.',
    ];

    public static function file(FileRecord $file, string $baseUrl): string
    {
        $kind = FileKind::fromName($file->name);
        $extension = FileKind::extension($file->name);
        $dlPath = '/dl/' . $file->id . '/' . FileName::urlSegment($file->name);
        $dlUrl = rtrim($baseUrl, '/') . $dlPath;

        $name = self::e($file->name);
        $size = self::e(self::humanSize($file->size));
        $exact = self::e(number_format($file->size, 0, '.', ' ') . ' bytes');
        $badge = $extension === null ? '' : '<span class="badge">.' . self::e($extension) . '</span>';
        $href = self::e($dlPath);
        $curl = self::e("curl -OJ '" . $dlUrl . "'");
        $cli = self::e('linkrot down ' . $file->id);
        $sha = self::e($file->sha256);
        $hue = $kind->hue();
        $glyph = $kind->glyph();
        // A copy button per box; hidden until the script runs, so that nobody gets a dead button.
        $box = static fn(string $id, string $text): string => '<div class="box"><code id="c-' . $id . '">' . $text . '</code>'
            . '<button type="button" class="copy" data-copy="c-' . $id . '" aria-label="Copy" title="Copy" hidden>' . self::COPY_ICONS . '</button></div>';

        $body = <<<HTML
            <article class="card">
            <div class="icon" style="--hue: $hue" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2.75h7.5L19.25 8.5v12.75H6z"/><path d="M13.5 2.75V8.5h5.75"/>$glyph</svg>
            </div>
            <h1 class="name">$name</h1>
            <p class="meta">$size $badge</p>
            <a class="button" href="$href">Download</a>
            <section class="terminal" aria-label="Download from the terminal">
            <h2>From a terminal</h2>
            <p class="label">linkrot</p>
            {$box('cli', $cli)}
            <p class="label">curl</p>
            {$box('curl', $curl)}
            <p class="label">SHA-256 <span class="muted">($exact)</span></p>
            {$box('sha', $sha)}
            </section>
            </article>
            HTML;

        return self::document($file->name, $body, self::tagline($file->id), true);
    }

    public static function notFound(): string
    {
        $body = <<<'HTML'
            <article class="card gone">
            <div class="icon" style="--hue: 150" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2.75h7.5L19.25 8.5v12.75H6z" stroke-dasharray="2.2 1.6"/><path d="M10 13.5l4 4m0-4l-4 4"/></svg>
            </div>
            <h1 class="name">Nothing here.</h1>
            <p class="meta">This link has rotted away, or it never existed.</p>
            </article>
            HTML;

        return self::document('Not found', $body, 'Told you it was rotting.');
    }

    /**
     * Content-Security-Policy of the file page: no script except the one inline script, pinned by its hash.
     * Everything else stays forbidden: no external resources, no inline event handlers.
     */
    public static function fileContentSecurityPolicy(): string
    {
        return "default-src 'none'; style-src 'unsafe-inline'; script-src 'sha256-" . base64_encode(hash('sha256', self::SCRIPT, true)) . "'";
    }

    /** @return list<string> */
    public static function taglines(): array
    {
        return self::TAGLINES;
    }

    public static function tagline(string $seed): string
    {
        return self::TAGLINES[crc32($seed) % count(self::TAGLINES)];
    }

    private static function document(string $title, string $body, string $tagline, bool $withScript = false): string
    {
        $script = $withScript ? '<script>' . self::SCRIPT . '</script>' : '';
        $title = self::e($title . ' · linkrot');
        $tagline = self::e($tagline);
        $css = self::CSS;
        $chain = self::CHAIN;

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="color-scheme" content="light dark">
            <meta name="robots" content="noindex, nofollow">
            <title>$title</title>
            <style>
            $css
            </style>
            </head>
            <body>
            <main>
            <header class="brand">$chain<span class="word">link<span class="rot">rot</span></span></header>
            $body
            <footer>$tagline</footer>
            </main>
            $script
            </body>
            </html>

            HTML;
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' bytes';
        }
        $value = (float) $bytes;
        $unit = '';
        foreach (['KiB', 'MiB', 'GiB', 'TiB'] as $unit) {
            $value /= 1024;
            if ($value < 1024) {
                break;
            }
        }

        return sprintf('%.1f %s', $value, $unit);
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** The copy button shows the first icon, and the second one for a moment after a successful copy. */
    private const string COPY_ICONS = '<svg class="i-copy" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/></svg><svg class="i-done" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

    /** The only script of the site (copy buttons). Changing it changes its hash, which the CSP header derives from it. */
    private const string SCRIPT = <<<'JS'
        (function () {
          function legacyCopy(el) {
            var range = document.createRange();
            range.selectNodeContents(el);
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) {}
            selection.removeAllRanges();
            return ok;
          }
          function copy(el) {
            if (navigator.clipboard && window.isSecureContext) {
              return navigator.clipboard.writeText(el.textContent).then(function () { return true; }, function () { return legacyCopy(el); });
            }
            return Promise.resolve(legacyCopy(el));
          }
          document.querySelectorAll('button[data-copy]').forEach(function (button) {
            button.hidden = false;
            button.addEventListener('click', function () {
              copy(document.getElementById(button.getAttribute('data-copy'))).then(function (ok) {
                button.classList.add(ok ? 'done' : 'failed');
                button.setAttribute('aria-label', ok ? 'Copied' : 'Copy failed');
                setTimeout(function () {
                  button.classList.remove('done', 'failed');
                  button.setAttribute('aria-label', 'Copy');
                }, 1500);
              });
            });
          });
        })();
        JS;

    /** Two chain links with a gap, and a few spores. */
    private const string CHAIN = '<svg class="chain" viewBox="0 0 28 24" width="28" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M10 7H6a5 5 0 0 0 0 10h4"/><path d="M18 7h4a5 5 0 0 1 0 10h-4"/><circle cx="14" cy="4" r="1" class="spore" stroke="none" fill="currentColor"/><circle cx="12.5" cy="20" r=".8" class="spore" stroke="none" fill="currentColor"/><circle cx="15.5" cy="21" r="1.1" class="spore" stroke="none" fill="currentColor"/></svg>';

    private const string CSS = <<<'CSS'
        :root {
          color-scheme: light dark;
          --bg: #f3f4ef; --card: #fff; --fg: #1c211d; --muted: #646f66; --line: #dde1d8;
          --accent: #3d7a4b; --accent-fg: #fff; --code-bg: #f0f2ec;
          --rot-a: #5a9a3c; --rot-b: #a07a2a;
        }
        @media (prefers-color-scheme: dark) {
          :root {
            --bg: #111411; --card: #1a1f1b; --fg: #e6ebe7; --muted: #94a096; --line: #2b332d;
            --accent: #7dbd8b; --accent-fg: #0e1a11; --code-bg: #131813;
            --rot-a: #8ccf5f; --rot-b: #c9a14a;
          }
        }
        * { box-sizing: border-box; }
        body {
          margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg);
          font: 16px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        main { max-width: 34rem; margin: 0 auto; padding: 2.5rem 1rem 2rem; }
        .brand { display: flex; align-items: center; justify-content: center; gap: .6rem; margin-bottom: 1.5rem; }
        .chain { color: var(--muted); }
        .spore { color: var(--rot-a); }
        .word { font-size: 1.6rem; font-weight: 700; letter-spacing: -.02em; }
        .rot {
          background: linear-gradient(90deg, var(--rot-a), var(--rot-b));
          -webkit-background-clip: text; background-clip: text; color: transparent;
          text-decoration: underline wavy var(--rot-b) 1px; text-underline-offset: .22em;
        }
        .card {
          background: var(--card); border: 1px solid var(--line); border-radius: 1rem;
          padding: 2rem 1.5rem; text-align: center; box-shadow: 0 1px 2px rgb(0 0 0 / .05), 0 8px 24px rgb(0 0 0 / .05);
        }
        .icon {
          display: inline-grid; place-items: center; width: 4.5rem; height: 4.5rem; border-radius: 1.1rem;
          color: hsl(var(--hue) 55% 40%); background: hsl(var(--hue) 60% 94%);
        }
        @media (prefers-color-scheme: dark) {
          .icon { color: hsl(var(--hue) 65% 70%); background: hsl(var(--hue) 25% 20%); }
        }
        .name { margin: 1rem 0 .25rem; font-size: 1.35rem; line-height: 1.3; overflow-wrap: anywhere; }
        .meta { margin: 0 0 1.5rem; color: var(--muted); }
        .badge {
          display: inline-block; margin-left: .35rem; padding: 0 .45rem; border: 1px solid var(--line);
          border-radius: .4rem; font: .8rem ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        }
        .button {
          display: block; padding: .8rem 1.2rem; border-radius: .7rem; background: var(--accent); color: var(--accent-fg);
          font-weight: 600; text-decoration: none;
        }
        .button:hover { filter: brightness(1.08); }
        .button:focus-visible { outline: 3px solid var(--rot-b); outline-offset: 3px; }
        @media (min-width: 30rem) { .button { display: inline-block; min-width: 14rem; } }
        .terminal { margin-top: 2rem; padding-top: 1.25rem; border-top: 1px solid var(--line); text-align: left; }
        .terminal h2 { margin: 0 0 .5rem; font-size: .8rem; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
        .label { margin: .8rem 0 .2rem; font-size: .85rem; color: var(--muted); }
        .muted { opacity: .8; }
        .box { position: relative; }
        .copy {
          position: absolute; top: 50%; right: .35rem; transform: translateY(-50%); display: grid; place-items: center;
          width: 2rem; height: 2rem; padding: 0; border: 0; border-radius: .45rem; background: var(--code-bg);
          color: var(--muted); cursor: pointer;
        }
        .copy[hidden] { display: none; }
        .copy:hover { color: var(--fg); }
        .copy:focus-visible { outline: 2px solid var(--rot-b); outline-offset: 1px; }
        .copy .i-done, .copy.done .i-copy { display: none; }
        .copy.done .i-done { display: block; }
        .copy.done { color: var(--accent); }
        .copy.failed { color: #c0504d; }
        code {
          display: block; padding: .5rem 2.8rem .5rem .7rem; border-radius: .5rem; background: var(--code-bg); border: 1px solid var(--line);
          font: .85rem/1.4 ui-monospace, "SF Mono", Menlo, Consolas, monospace; overflow-x: auto; white-space: nowrap; user-select: all;
        }
        .gone .meta { margin-bottom: 0; }
        footer { margin-top: 1.5rem; text-align: center; font-size: .9rem; font-style: italic; color: var(--muted); }
        CSS;
}

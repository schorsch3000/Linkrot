<?php

declare(strict_types=1);

namespace Linkrot\Web;

use Linkrot\FileName;
use Linkrot\Files\FileRecord;

/** HTML of the public pages: plain, no JavaScript, no external resources, light and dark. */
final class Page
{
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
        $curl = "curl -OJ '" . $dlUrl . "'";
        $cli = 'linkrot down ' . $file->id;
        $sha = $file->sha256;
        $hue = $kind->hue();
        $glyph = $kind->glyph();
        $box = self::copyBox(...);
        $qr = QrCode::svg($file->url($baseUrl), 'QR code of this page');

        $body = <<<HTML
            <article class="card">
            <div class="icon" style="--hue: $hue" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="52" height="52" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2.75h7.5L19.25 8.5v12.75H6z"/><path d="M13.5 2.75V8.5h5.75"/>$glyph</svg>
            </div>
            <h1 class="name">$name</h1>
            <p class="meta">$size $badge</p>
            <div class="actions">
            <a class="button" href="$href">Download</a>
            <button type="button" class="button alt" data-qr="qr" hidden>QR code</button>
            </div>
            <dialog class="qr" id="qr" aria-label="QR code of this page">
            <div class="qr-body">
            $qr
            <p class="small">Scan to open this page</p>
            <form method="dialog"><button class="button alt">Close</button></form>
            </div>
            </dialog>
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

    /** The about page at `/`. No script, no copy buttons: only static text and one link to the project. */
    public static function home(): string
    {
        $chain = self::CHAIN_LARGE;
        $project = self::e(self::PROJECT_URL);

        $body = <<<HTML
            <article class="card about">
            <div class="icon" style="--hue: 150" aria-hidden="true">$chain</div>
            <h1 class="name">Links that rot.</h1>
            <p class="lead">linkrot is a small private file sharing service for the terminal. Upload a file, share the link, and
            let it rot: after a while the link stops working and the file is gone.</p>
            <ol class="steps">
            <li><strong>Upload</strong> a file with the command line client.</li>
            <li><strong>Share</strong> the link it prints.</li>
            <li><strong>Rot.</strong> The link and the file disappear on their own, nothing to clean up.</li>
            </ol>
            <a class="button" href="$project">linkrot on GitHub</a>
            <p class="small">This is a private instance: there are no accounts and no uploads from the browser.</p>
            </article>
            HTML;

        return self::document('Links that rot', $body, self::taglines()[0]);
    }

    public static function notFound(): string
    {
        $body = <<<'HTML'
            <article class="card gone">
            <div class="icon" style="--hue: 150" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="52" height="52" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2.75h7.5L19.25 8.5v12.75H6z" stroke-dasharray="2.2 1.6"/><path d="M10 13.5l4 4m0-4l-4 4"/></svg>
            </div>
            <h1 class="name">Nothing here.</h1>
            <p class="meta">This link has rotted away, or it never existed.</p>
            </article>
            HTML;

        return self::document('Not found', $body, 'Told you it was rotting.');
    }

    /**
     * Content-Security-Policy of pages with a script (the file page): style and script only from this origin (htdocs/assets),
     * no inline script and no inline event handlers. Inline `style` attributes are allowed for the icon colour (--hue) only.
     * Everything else stays forbidden: no external resources.
     */
    public static function scriptContentSecurityPolicy(): string
    {
        return self::staticContentSecurityPolicy() . "; script-src 'self'";
    }

    /** @return list<string> */
    public static function taglines(): array
    {
        /** @var list<string> $taglines */
        static $taglines = require __DIR__ . '/taglines.php';

        return $taglines;
    }

    /** Content-Security-Policy of pages without a script (about, not found): the stylesheet and the favicon, from this origin. */
    public static function staticContentSecurityPolicy(): string
    {
        return "default-src 'none'; img-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'";
    }

    public static function tagline(string $seed): string
    {
        $taglines = self::taglines();

        return $taglines[crc32($seed) % count($taglines)];
    }

    private static function document(string $title, string $body, string $tagline, bool $withScript = false): string
    {
        $script = $withScript ? '<script src="' . Assets::url('page.js') . '" defer></script>' : '';
        $title = self::e($title . ' · linkrot');
        $tagline = self::e($tagline);
        $css = Assets::url('page.css');
        $chain = self::CHAIN;

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="color-scheme" content="light dark">
            <meta name="robots" content="noindex, nofollow">
            <link rel="icon" href="/favicon.svg" type="image/svg+xml">
            <link rel="icon" href="/favicon.ico" sizes="any">
            <link rel="apple-touch-icon" href="/apple-touch-icon.png">
            <title>$title</title>
            <link rel="stylesheet" href="$css">
            </head>
            <body>
            <main>
            <header class="brand"><a href="/">$chain<span class="word">link<span class="rot">rot</span></span></a></header>
            $body
            <footer>$tagline</footer>
            </main>
            $script
            </body>
            </html>

            HTML;
    }

    /** One command in a box with a copy button (hidden until the script runs, so that nobody gets a dead button). */
    private static function copyBox(string $id, string $text): string
    {
        $text = self::e($text);

        return '<div class="box"><code id="c-' . $id . '">' . $text . '</code>'
            . '<button type="button" class="copy" data-copy="c-' . $id . '" aria-label="Copy" title="Copy" hidden>' . self::COPY_ICONS . '</button></div>';
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

    /** Where the button of the about page points. */
    private const string PROJECT_URL = 'https://github.com/schorsch3000/Linkrot';

    /** The mark of the headline, bigger, for the icon tile of the about page. */
    private const string CHAIN_LARGE = '<svg viewBox="0 0 28 24" width="44" height="38" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 7H6a5 5 0 0 0 0 10h4"/><path d="M18 7h4a5 5 0 0 1 0 10h-4"/><circle cx="14" cy="4" r="1" stroke="none" fill="currentColor"/><circle cx="15.5" cy="21" r="1.1" stroke="none" fill="currentColor"/></svg>';

    /** Two chain links with a gap, and a few spores. */
    private const string CHAIN = '<svg class="chain" viewBox="0 0 28 24" width="28" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M10 7H6a5 5 0 0 0 0 10h4"/><path d="M18 7h4a5 5 0 0 1 0 10h-4"/><circle cx="14" cy="4" r="1" class="spore" stroke="none" fill="currentColor"/><circle cx="12.5" cy="20" r=".8" class="spore" stroke="none" fill="currentColor"/><circle cx="15.5" cy="21" r="1.1" class="spore" stroke="none" fill="currentColor"/></svg>';
}

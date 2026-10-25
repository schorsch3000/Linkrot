<?php

declare(strict_types=1);

use Linkrot\Files\FileRecord;
use Linkrot\Files\FileStatus;
use Linkrot\Web\Page;

/*
 * Writes sample download pages (one per icon type, plus the not-found page) to build/preview/ so that the design can
 * be looked at in a browser, in light and dark mode, without a server.
 * usage: php scripts/preview.php   (make preview)
 */
require __DIR__ . '/../app/vendor/autoload.php';

$dir = __DIR__ . '/../build/preview';
if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
    fwrite(STDERR, "cannot create $dir\n");
    exit(1);
}

$samples = [
    'image' => ['holiday-photo.jpg', 4_200_000],
    'video' => ['screen-recording-2026-10-24.mkv', 1_800_000_000],
    'audio' => ['mixtape.flac', 38_000_000],
    'archive' => ['backup-2026-10.tar.gz', 912_000_000],
    'code' => ['main.go', 8_400],
    'document' => ['quarterly-report.pdf', 1_234_567],
    'disk' => ['debian-13-netinst.iso', 780_000_000],
    'generic' => ['Makefile', 2_048],
    'long' => ['a-rather-long-file-name-so-that-the-curl-command-and-the-checksum-must-scroll-sideways-2026.tar.gz', 52_428_800],
];

$links = [];
foreach ($samples as $kind => [$name, $size]) {
    $file = new FileRecord('abcd7KfRtm4pWxn' . $kind[0], $name, $size, hash('sha256', $name), FileStatus::Ready, $size, 0, 0, 0, 0, 0, null);
    file_put_contents("$dir/file-$kind.html", Page::file($file, 'https://linkrot.example'));
    $links[] = "<li><a href=\"file-$kind.html\">$kind: " . htmlspecialchars($name) . '</a></li>';
}
file_put_contents("$dir/home.html", Page::home());
$links[] = '<li><a href="home.html">about page (/)</a></li>';
file_put_contents("$dir/notfound.html", Page::notFound());
$links[] = '<li><a href="notfound.html">not found</a></li>';
file_put_contents("$dir/index.html", '<!doctype html><meta charset="utf-8"><meta name="color-scheme" content="light dark"><title>previews</title><ul>' . implode('', $links) . '</ul>');

echo realpath("$dir/index.html"), "\n";

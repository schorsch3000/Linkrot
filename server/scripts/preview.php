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
    'subtitles' => ['movie.en.srt', 96_000],
    'audio' => ['mixtape.flac', 38_000_000],
    'archive' => ['backup-2026-10.tar.gz', 912_000_000],
    'secret' => ['secret.txt.age', 1_200],
    'code' => ['main.go', 8_400],
    'document' => ['quarterly-report.pdf', 1_234_567],
    'sheet' => ['budget-2026.xlsx', 88_000],
    'slides' => ['talk.pptx', 6_400_000],
    'ebook' => ['novel.epub', 1_900_000],
    'disk' => ['debian-13-netinst.iso', 780_000_000],
    'program' => ['setup.exe', 42_000_000],
    'mesh' => ['benchy.stl', 11_300_000],
    'cad' => ['bracket-assembly.step', 2_700_000],
    'gcode' => ['benchy.bgcode', 3_100_000],
    'database' => ['app.sqlite', 25_000_000],
    'map' => ['saturday-ride.gpx', 410_000],
    'font' => ['inter.woff2', 110_000],
    'c64' => ['turrican2.d64', 174_848],
    'retro' => ['game.nes', 262_160],
    'generic' => ['Makefile', 2_048],
    'long' => ['a-rather-long-file-name-so-that-the-curl-command-and-the-checksum-must-scroll-sideways-2026.tar.gz', 52_428_800],
];

$links = [];
// The pages link /assets/...; next to the files in a folder they need relative links and a copy of the assets.
$page = static fn(string $html): string => str_replace(['href="/assets/', 'src="/assets/'], ['href="assets/', 'src="assets/'], $html);
$assets = "$dir/assets";
if (!is_dir($assets) && !mkdir($assets, 0o775, true) && !is_dir($assets)) {
    fwrite(STDERR, "cannot create $assets\n");
    exit(1);
}
foreach (glob(__DIR__ . '/../htdocs/assets/*') ?: [] as $asset) {
    copy($asset, $assets . '/' . basename($asset));
}

$number = 0;
foreach ($samples as $kind => [$name, $size]) {
    $file = new FileRecord(sprintf('abcd7KfRtm4pW%03d', ++$number), $name, $size, hash('sha256', $name), FileStatus::Ready, $size, 0, 0, 0, 0, 0, null);
    file_put_contents("$dir/file-$kind.html", $page(Page::file($file, 'https://linkrot.example')));
    $links[] = "<li><a href=\"file-$kind.html\">$kind: " . htmlspecialchars($name) . '</a></li>';
}
file_put_contents("$dir/home.html", $page(Page::home()));
$links[] = '<li><a href="home.html">about page (/)</a></li>';
file_put_contents("$dir/notfound.html", $page(Page::notFound()));
$links[] = '<li><a href="notfound.html">not found</a></li>';
file_put_contents("$dir/index.html", '<!doctype html><meta charset="utf-8"><meta name="color-scheme" content="light dark"><title>previews</title><ul>' . implode('', $links) . '</ul>');

echo realpath("$dir/index.html"), "\n";

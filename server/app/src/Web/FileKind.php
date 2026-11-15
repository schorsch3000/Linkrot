<?php

declare(strict_types=1);

namespace Linkrot\Web;

/**
 * Rough type of a file by its extension: decides the icon on the download page.
 *
 * Kinds of one family share their colour and are told apart by their glyph (the page shows the exact extension next to
 * the size anyway): documents, sheets, slides and e-books are blue; 3D models, CAD files and G-code are magenta; disks
 * and programs are indigo; archives and secrets are amber; video and subtitles are pink; databases, maps and fonts are
 * lime; the C64 and the other 8 bit machines are yellow. An extension belongs to exactly one kind.
 */
enum FileKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Subtitles = 'subtitles';
    case Audio = 'audio';
    case Archive = 'archive';
    case Secret = 'secret';
    case Code = 'code';
    case Document = 'document';
    case Sheet = 'sheet';
    case Slides = 'slides';
    case Ebook = 'ebook';
    case Disk = 'disk';
    case Program = 'program';
    case Mesh = 'mesh';
    case Cad = 'cad';
    case Gcode = 'gcode';
    case Database = 'database';
    case Map = 'map';
    case Font = 'font';
    case C64 = 'c64';
    case Retro = 'retro';
    case Generic = 'generic';

    private const array COMPOUND_EXTENSIONS = ['tar.gz', 'tar.xz', 'tar.bz2', 'tar.zst', 'tar.lz4'];

    public static function fromName(string $name): self
    {
        $extension = self::extension($name);
        if ($extension === null) {
            return self::Generic;
        }
        $last = substr($extension, (int) strrpos('.' . $extension, '.'));
        foreach (self::cases() as $kind) {
            if (in_array($last, explode(' ', $kind->extensions()), true)) {
                return $kind;
            }
        }

        return self::Generic;
    }

    /** Extension for the little badge (`pdf`, `tar.gz`), null if there is no sensible one. */
    public static function extension(string $name): ?string
    {
        $lower = strtolower($name);
        foreach (self::COMPOUND_EXTENSIONS as $compound) {
            if (str_ends_with($lower, '.' . $compound) && strlen($lower) > strlen($compound) + 1) {
                return $compound;
            }
        }
        $dot = strrpos($lower, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($lower) - 1) {
            return null; // no extension, or a hidden file like .bashrc
        }
        $extension = substr($lower, $dot + 1);

        return preg_match('~^[a-z0-9]{1,8}$~D', $extension) === 1 ? $extension : null;
    }

    /** Hue (0-360) of the icon colour. */
    public function hue(): int
    {
        return match ($this) {
            self::Image => 275,
            self::Video, self::Subtitles => 345,
            self::Audio => 190,
            self::Archive, self::Secret => 32,
            self::Code => 145,
            self::Document, self::Sheet, self::Slides, self::Ebook => 215,
            self::Disk, self::Program => 255,
            self::Mesh, self::Cad, self::Gcode => 310,
            self::Database, self::Map, self::Font => 100,
            self::C64, self::Retro => 60,
            self::Generic => 150,
        };
    }

    /** SVG elements drawn inside the page outline (24x24 viewBox). */
    public function glyph(): string
    {
        return match ($this) {
            self::Image => '<circle cx="10" cy="12" r="1.2"/><path d="M8 20l3.5-4.5 2.5 3 2-2.5 3 4z" fill="currentColor"/>',
            self::Video => '<path d="M10.5 12.5v6l5-3z" fill="currentColor"/>',
            self::Audio => '<path d="M14 11v6.5M14 11l3 1"/><circle cx="12.3" cy="17.5" r="1.7" fill="currentColor"/>',
            self::Archive => '<path d="M13 9.5v2m0 2v2m0 2v2" stroke-dasharray="1.6 1.6"/><rect x="11.2" y="16" width="3.6" height="3" rx=".8"/>',
            self::Code => '<path d="M11 12.5l-2.5 3 2.5 3M15 12.5l2.5 3-2.5 3"/>',
            self::Document => '<path d="M9 13h8M9 16h8M9 19h5"/>',
            self::Disk => '<circle cx="13" cy="16" r="3.8"/><circle cx="13" cy="16" r=".9" fill="currentColor"/>',
            self::C64 => '<g transform="translate(8 10.8) scale(0.08495)" fill="currentColor" stroke="none"><path d="m52.625 0c-29.054 0-52.625 23.571-52.625 52.625s23.571 52.594 52.625 52.594c5.5874 0 10.98-0.88711 16.031-2.5v-26.25c-4.3621 2.5362-9.4669 4-14.938 4-15.988 0-28.938-12.465-28.938-27.844s12.95-27.844 28.938-27.844c5.4706 0 10.575 1.4638 14.938 4v-26.281c-5.0513-1.6148-10.444-2.5-16.031-2.5z"/><path d="M108.31 32.61 90.78 50.87 69.12 50.87 69.12 32.61z M108.31 72.82 90.78 54.57 69.12 54.57 69.12 72.82z"/></g>',
            self::Subtitles => '<path d="M9 12h8v5.5h-4l-2.5 2v-2H9z"/><path d="M10.8 14.8h4.4"/>',
            self::Secret => '<rect x="9.5" y="15" width="7" height="5" rx="1"/><path d="M11 15v-1.6a2 2 0 014 0V15"/>',
            self::Sheet => '<rect x="9" y="11.5" width="8" height="8" rx="1"/><path d="M9 14.2h8M9 16.9h8M13 11.5v8"/>',
            self::Slides => '<rect x="8.5" y="11.5" width="9" height="6" rx=".8"/><path d="M13 17.5v2.2M10.6 19.7h4.8"/>',
            self::Ebook => '<path d="M13 12.6c-1.4-1-3.1-1.2-4.5-1v7.4c1.4-.2 3.1 0 4.5 1 1.4-1 3.1-1.2 4.5-1v-7.4c-1.4-.2-3.1 0-4.5 1zM13 12.6v7.4"/>',
            self::Program => '<path d="M13 11.5v5.3M10.7 14.6l2.3 2.3 2.3-2.3M9.2 19.6h7.6"/>',
            self::Mesh => '<path d="M13 11.4l4 2.3v4.6l-4 2.3-4-2.3v-4.6z"/><path d="M13 16v4.6M13 16l-4-2.3M13 16l4-2.3"/>',
            self::Cad => '<path d="M9 11.6h8M9 10.6v2M17 10.6v2"/><rect x="9" y="14.6" width="8" height="5.4" rx="1"/><circle cx="13" cy="17.3" r="1.3"/>',
            self::Gcode => '<path d="M9 12.4h8v3.2H9v3.2h5.6"/><circle cx="16.9" cy="18.8" r="1" fill="currentColor"/>',
            self::Database => '<ellipse cx="13" cy="12.8" rx="4" ry="1.5"/><path d="M9 12.8v6.2c0 .9 1.8 1.6 4 1.6s4-.7 4-1.6v-6.2M9 15.9c0 .9 1.8 1.6 4 1.6s4-.7 4-1.6"/>',
            self::Map => '<path d="M13 20.2s-3.6-3.5-3.6-6.2a3.6 3.6 0 017.2 0c0 2.7-3.6 6.2-3.6 6.2z"/><circle cx="13" cy="14" r="1.2"/>',
            self::Font => '<path d="M9.4 20l3.6-8.6 3.6 8.6M10.8 17.2h4.4"/>',
            self::Retro => '<rect x="9" y="11.8" width="8" height="8.2" rx="1"/><rect x="10.8" y="13.5" width="4.4" height="3" rx=".4"/><path d="M11 20v-1.6h4V20"/>',
            self::Generic => '',
        };
    }

    /**
     * The extensions (lower case, without the dot) that belong to this kind.
     *
     * @return list<string>
     */
    public function knownExtensions(): array
    {
        $extensions = $this->extensions();

        return $extensions === '' ? [] : explode(' ', $extensions);
    }

    private function extensions(): string
    {
        return match ($this) {
            self::Image => 'png jpg jpeg gif webp svg avif heic heif bmp tif tiff ico raw cr2 nef dng psd xcf',
            self::Video => 'mp4 mkv mov webm avi m4v wmv flv mpg mpeg ogv 3gp',
            self::Subtitles => 'srt vtt ass ssa sbv',
            self::Audio => 'mp3 flac wav ogg oga m4a opus aac wma aiff mid midi',
            self::Archive => 'zip tar gz tgz bz2 xz zst 7z rar lz lz4 cab',
            self::Secret => 'age gpg pgp asc kdbx kdb pem cer p12 pfx csr jks',
            self::Code => 'php go js mjs ts tsx jsx py rb rs c h cc cpp hpp java kt swift cs sh bash bas a asm zsh fish pl lua json yaml yml toml xml html htm css scss vue dart ex exs hs clj scala scad',
            self::Document => 'txt md rst pdf doc docx odt rtf log tex',
            self::Sheet => 'xls xlsx xlsm ods csv tsv numbers',
            self::Slides => 'ppt pptx ppsx odp key',
            self::Ebook => 'epub mobi azw azw3 fb2 cbz cbr',
            self::Disk => 'iso img bin vmdk qcow2 vdi vhd vhdx ova',
            self::Program => 'exe msi dmg pkg deb rpm apk appimage ipa jar',
            self::Mesh => 'stl 3mf obj ply glb gltf fbx blend dae 3ds usdz skp',
            self::Cad => 'step stp iges igs f3d fcstd sldprt sldasm ipt iam catpart dwg dxf 3dm',
            self::Gcode => 'gcode gco bgcode',
            self::Database => 'sqlite sqlite3 db mdb accdb parquet sql',
            self::Map => 'gpx kml kmz geojson osm mbtiles shp',
            self::Font => 'ttf otf woff woff2 eot ttc',
            self::C64 => 'd64 g64 d81 g81 t64 crt prg p00 tap d71 g71 p64 dnp dhp seq usr sid',
            self::Retro => 'nes sfc smc gb gbc gba nds n64 z64 v64 rom adf adz tzx z80 sna',
            self::Generic => '',
        };
    }
}

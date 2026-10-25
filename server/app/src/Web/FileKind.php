<?php

declare(strict_types=1);

namespace Linkrot\Web;

/** Rough type of a file by its extension: decides the icon on the download page. */
enum FileKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Archive = 'archive';
    case Code = 'code';
    case Document = 'document';
    case Disk = 'disk';
    case C64 = 'c64';
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

        return preg_match('~^[a-z0-9]{1,8}$~', $extension) === 1 ? $extension : null;
    }

    /** Hue (0-360) of the icon colour. */
    public function hue(): int
    {
        return match ($this) {
            self::Image => 275,
            self::Video => 345,
            self::Audio => 190,
            self::Archive => 32,
            self::Code => 145,
            self::Document => 215,
            self::Disk => 255,
            self::C64 => 60,
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
            self::Generic => '',
        };
    }

    private function extensions(): string
    {
        return match ($this) {
            self::Image => 'png jpg jpeg gif webp svg avif heic heif bmp tif tiff ico raw cr2 nef dng psd xcf',
            self::Video => 'mp4 mkv mov webm avi m4v wmv flv mpg mpeg ogv 3gp ts',
            self::Audio => 'mp3 flac wav ogg oga m4a opus aac wma aiff mid midi',
            self::Archive => 'zip tar gz tgz bz2 xz zst 7z rar lz lz4 cab',
            self::Code => 'php go js mjs ts tsx jsx py rb rs c h cc cpp hpp java kt swift cs sh bash bas a asm zsh fish pl lua sql json yaml yml toml xml html htm css scss vue dart ex exs hs clj scala',
            self::Document => 'txt md rst pdf doc docx odt rtf log csv tsv xls xlsx ods ppt pptx odp epub tex',
            self::Disk => 'iso img bin exe msi dmg pkg deb rpm apk appimage vmdk qcow2 vdi',
            self::C64 => 'd64 g64 d81 g81 t64 crt prg p00 tap d71 g71 p64 dnp dhp seq usr sid',
            self::Generic => '',
        };
    }
}

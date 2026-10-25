<?php

declare(strict_types=1);

namespace Linkrot\Web;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** A QR code as inline SVG: black on white with a quiet zone, scaled by CSS. */
final class QrCode
{
    public static function svg(string $text, string $label): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(256, 2), new SvgImageBackEnd()));
        $svg = $writer->writeString($text, 'UTF-8', ErrorCorrectionLevel::M());
        $svg = (string) preg_replace('~^<\?xml[^>]*\?>\s*~', '', $svg);
        $label = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return (string) preg_replace('~^<svg [^>]*?width="256" height="256" ~', '<svg role="img" aria-label="' . $label . '" ', $svg, 1);
    }
}

"""Renders htdocs/favicon.ico and htdocs/apple-touch-icon.png from the geometry of htdocs/favicon.svg.

usage: uv run --with pillow python scripts/make-icons.py
(The icons are committed, this is only needed when the design changes. Keep the numbers in sync with the SVG.)
"""

import io
import struct
from pathlib import Path

from PIL import Image, ImageDraw

HTDOCS = Path(__file__).resolve().parent.parent / "htdocs"
TILE, CHAIN, SPORE_A, SPORE_B = "#1b2a20", "#8fd19e", "#d0a84a", "#8fbf4f"
STROKE = 3.2
SUPER = 16  # supersampling factor, downscaled with a high quality filter


def draw(size: int, rounded: bool = True, spores: bool = True) -> Image.Image:
    """The mark on a size x size canvas. Geometry is in the 32 unit coordinates of the SVG."""
    scale = size * SUPER / 32
    image = Image.new("RGBA", (size * SUPER, size * SUPER), (0, 0, 0, 0))
    d = ImageDraw.Draw(image)

    def s(*values: float) -> list[float]:
        return [v * scale for v in values]

    d.rounded_rectangle(s(0, 0, 32, 32), radius=7 * scale if rounded else 0, fill=TILE)

    width = round(STROKE * scale)
    cap = STROKE / 2

    def line(x1: float, y1: float, x2: float, y2: float) -> None:
        d.line(s(x1, y1, x2, y2), fill=CHAIN, width=width)
        for x, y in ((x1, y1), (x2, y2)):  # round caps
            d.ellipse(s(x - cap, y - cap, x + cap, y + cap), fill=CHAIN)

    # left link opens to the right, right link opens to the left; a wide gap keeps them apart at 16 pixels
    line(9.5, 11, 12.5, 11)
    line(9.5, 21, 12.5, 21)
    d.arc(s(4.5, 11, 14.5, 21), 90, 270, fill=CHAIN, width=width)
    line(19.5, 11, 22.5, 11)
    line(19.5, 21, 22.5, 21)
    d.arc(s(17.5, 11, 27.5, 21), 270, 90, fill=CHAIN, width=width)

    if spores:
        for (cx, cy, r), color in (((16, 6.6, 1.4), SPORE_A), ((14.6, 25.6, 1.1), SPORE_B), ((17.6, 26.4, 1.5), SPORE_A)):
            d.ellipse(s(cx - r, cy - r, cx + r, cy + r), fill=color)

    return image.resize((size, size), Image.LANCZOS)


def write_ico(path: Path, images: list[Image.Image]) -> None:
    """An .ico with one PNG per size. (Pillow would scale a single image down, the 16 pixel one is drawn on its own.)"""
    blobs = []
    for image in images:
        buffer = io.BytesIO()
        image.save(buffer, "PNG", optimize=True)
        blobs.append(buffer.getvalue())
    data = struct.pack("<HHH", 0, 1, len(images))
    offset = 6 + 16 * len(images)
    for image, blob in zip(images, blobs, strict=True):
        w, h = image.size
        data += struct.pack("<BBBBHHII", w % 256, h % 256, 0, 0, 1, 32, len(blob), offset)
        offset += len(blob)
    path.write_bytes(data + b"".join(blobs))


def main() -> None:
    write_ico(HTDOCS / "favicon.ico", [draw(16, spores=False), draw(32), draw(48)])
    draw(180, rounded=False).convert("RGB").save(HTDOCS / "apple-touch-icon.png", optimize=True)


main()

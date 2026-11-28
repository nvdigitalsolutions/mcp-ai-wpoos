#!/usr/bin/env python3
"""Generate the WordPress.org listing assets for NV oOS Design System.

Produces the icon and banner set in ``.wordpress-org/``:

    icon-128x128.png
    icon-256x256.png
    banner-772x250.png
    banner-1544x500.png

The artwork is a token-swatch motif drawn from the plugin's own palette
(dark green page, olive accent, teal secondary, cream text) so the listing
matches the product. Requires Pillow; not shipped with the plugin.

Usage:
    python bin/generate-wporg-assets.py
"""

from __future__ import annotations

import os
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

# Palette (mirrors the letterhead email template).
PAGE = (15, 30, 24)
CARD = (26, 36, 32)
OLIVE = (201, 185, 110)
TEAL = (77, 138, 123)
CREAM = (232, 228, 220)
MUTED = (154, 148, 136)

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / ".wordpress-org"

FONT_CANDIDATES = (
    r"C:\Windows\Fonts\seguisb.ttf",
    r"C:\Windows\Fonts\segoeuib.ttf",
    r"C:\Windows\Fonts\arialbd.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
)


def load_font(size):
    """Load a bold sans font at ``size``, falling back to PIL's default."""
    for candidate in FONT_CANDIDATES:
        if os.path.exists(candidate):
            try:
                return ImageFont.truetype(candidate, size)
            except OSError:
                continue
    return ImageFont.load_default()


def rounded(draw, box, radius, fill):
    """Draw a filled rounded rectangle."""
    draw.rounded_rectangle(box, radius=radius, fill=fill)


def draw_mark(img, x, y, size):
    """Draw the 2x2 token-swatch mark at ``x``, ``y`` with ``size`` square."""
    draw = ImageDraw.Draw(img)
    pad = max(2, size // 16)
    cell = (size - pad * 3) // 2
    radius = max(3, cell // 4)

    positions = (
        (x + pad, y + pad, OLIVE),
        (x + pad * 2 + cell, y + pad, TEAL),
        (x + pad, y + pad * 2 + cell, CREAM),
        (x + pad * 2 + cell, y + pad * 2 + cell, CARD),
    )
    for cx, cy, colour in positions:
        rounded(draw, (cx, cy, cx + cell, cy + cell), radius, colour)


def make_icon(size):
    """Square icon with the mark centered on the page colour."""
    img = Image.new("RGB", (size, size), PAGE)
    mark = int(size * 0.62)
    draw_mark(img, (size - mark) // 2, (size - mark) // 2 - max(1, size // 40), mark)
    return img


def make_banner(width, height):
    """Wide banner: mark on the left, wordmark and rule on the right."""
    img = Image.new("RGB", (width, height), PAGE)

    scale = height / 250
    mark = int(96 * scale)
    margin = int(36 * scale)
    draw_mark(img, margin, (height - mark) // 2, mark)

    draw = ImageDraw.Draw(img)

    title_size = int(38 * scale)
    subtitle_size = int(19 * scale)
    title_font = load_font(title_size)
    subtitle_font = load_font(subtitle_size)

    text_x = margin + mark + int(28 * scale)
    title = "NV oOS Design System"
    subtitle = "Design tokens \u00b7 Global Styles \u00b7 Branded email"

    title_box = draw.textbbox((0, 0), title, font=title_font)
    subtitle_box = draw.textbbox((0, 0), subtitle, font=subtitle_font)

    block = (title_box[3] - title_box[1]) + int(14 * scale) + (subtitle_box[3] - subtitle_box[1])
    top = (height - block) // 2

    draw.text((text_x, top - title_box[1]), title, font=title_font, fill=CREAM)

    rule_y = top - title_box[1] + (title_box[3] - title_box[1]) + int(7 * scale)
    draw.line(
        (text_x, rule_y, text_x + int(46 * scale), rule_y),
        fill=OLIVE,
        width=max(2, int(2 * scale)),
    )

    draw.text(
        (text_x, rule_y + int(7 * scale) - subtitle_box[1]),
        subtitle,
        font=subtitle_font,
        fill=MUTED,
    )

    return img


def main():
    """Write every listing asset."""
    OUT.mkdir(parents=True, exist_ok=True)

    make_icon(256).save(OUT / "icon-256x256.png", optimize=True)
    make_icon(128).save(OUT / "icon-128x128.png", optimize=True)
    make_banner(1544, 500).save(OUT / "banner-1544x500.png", optimize=True)
    make_banner(772, 250).save(OUT / "banner-772x250.png", optimize=True)

    for name in (
        "icon-128x128.png",
        "icon-256x256.png",
        "banner-772x250.png",
        "banner-1544x500.png",
    ):
        path = OUT / name
        print("{}: {} bytes".format(name, path.stat().st_size))


if __name__ == "__main__":
    main()

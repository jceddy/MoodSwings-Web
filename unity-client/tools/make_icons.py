#!/usr/bin/env python3
"""Generate the placeholder app icons (a "MOOD" wordmark in the UI's colors) under Assets/Icons/.

    pip install pillow
    python tools/make_icons.py

Writes:
    icon-1024.png         full-bleed square icon (also the source for the Windows .ico)
    android-background.png / android-foreground.png   the adaptive icon's two layers (432x432; the
                          foreground keeps its art inside the central 66% so any mask shape works)
    MOOD.ico              multi-size Windows icon (used by the installer and the .exe)

To use real art instead, replace icon-1024.png (and the two android-* layers) with your own and run
only the .ico step: python tools/make_icons.py --from-icon
"""
import argparse
import sys
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

OUT = Path(__file__).resolve().parent.parent / "Assets" / "Icons"
BACKGROUND = (18, 20, 26, 255)   # UiTheme.background
ACCENT = (242, 173, 51, 255)     # UiTheme.accent
FONT_CANDIDATES = [
    "C:/Windows/Fonts/arialbd.ttf",
    "/Library/Fonts/Arial Bold.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
]


def font(size: int) -> ImageFont.FreeTypeFont:
    for path in FONT_CANDIDATES:
        if Path(path).exists():
            return ImageFont.truetype(path, size)
    return ImageFont.load_default()


def wordmark(size: int, fraction: float) -> Image.Image:
    """The word MOOD in the accent color, centered on a transparent square, `fraction` of its width wide."""
    layer = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)
    text = "MOOD"
    target = size * fraction
    px = size
    while px > 8:
        f = font(px)
        box = draw.textbbox((0, 0), text, font=f)
        if box[2] - box[0] <= target:
            break
        px -= 4
    box = draw.textbbox((0, 0), text, font=f)
    x = (size - (box[2] - box[0])) / 2 - box[0]
    y = (size - (box[3] - box[1])) / 2 - box[1]
    draw.text((x, y), text, font=f, fill=ACCENT)
    # A thin accent rule under the word, like the title screen's tagline underline.
    rule_y = y + box[3] + size * 0.035
    draw.rectangle([ (size - target) / 2, rule_y, (size + target) / 2, rule_y + size * 0.012 ], fill=ACCENT)
    return layer


def make_icons() -> None:
    OUT.mkdir(parents=True, exist_ok=True)

    full = Image.new("RGBA", (1024, 1024), BACKGROUND)
    full.alpha_composite(wordmark(1024, 0.74))
    full.save(OUT / "icon-1024.png")

    Image.new("RGBA", (432, 432), BACKGROUND).save(OUT / "android-background.png")
    wordmark(432, 0.66 * 0.86).save(OUT / "android-foreground.png")


def make_icons() -> None:
    OUT.mkdir(parents=True, exist_ok=True)

    full = Image.new("RGBA", (1024, 1024), BACKGROUND)
    full.alpha_composite(wordmark(1024, 0.74))
    full.save(OUT / "icon-1024.png")

    Image.new("RGBA", (432, 432), BACKGROUND).save(OUT / "android-background.png")
    wordmark(432, 0.66 * 0.86).save(OUT / "android-foreground.png")


def make_icons() -> None:
    OUT.mkdir(parents=True, exist_ok=True)

    full = Image.new("RGBA", (1024, 1024), BACKGROUND)
    full.alpha_composite(wordmark(1024, 0.74))
    full.save(OUT / "icon-1024.png")

    Image.new("RGBA", (432, 432), BACKGROUND).save(OUT / "android-background.png")
    wordmark(432, 0.66 * 0.86).save(OUT / "android-foreground.png")

    for name, mask in (("android-round.png", "circle"), ("android-legacy.png", "rounded")):
        icon = full.resize((192, 192), Image.LANCZOS)
        shape = Image.new("L", (192, 192), 0)
        draw = ImageDraw.Draw(shape)
        if mask == "circle":
            draw.ellipse([0, 0, 191, 191], fill=255)
        else:
            draw.rounded_rectangle([0, 0, 191, 191], radius=36, fill=255)
        icon.putalpha(shape)
        icon.save(OUT / name)


def make_ico() -> None:
    source = Image.open(OUT / "icon-1024.png").convert("RGBA")
    source.save(OUT / "MOOD.ico", sizes=[(16, 16), (24, 24), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)])


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--from-icon", action="store_true", help="only rebuild MOOD.ico from icon-1024.png")
    args = parser.parse_args()
    if not args.from_icon:
        make_icons()
    make_ico()
    print(f"icons -> {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

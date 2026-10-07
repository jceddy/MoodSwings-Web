#!/usr/bin/env python3
"""Convert web-static's card art (.webp) to PNGs Unity can import.

Unity can't import WebP, and the art is already checked in under
web-static/img/, so this regenerates a Unity-friendly copy on demand rather
than committing a second set of (much larger) PNGs. Output is git-ignored.

    pip install pillow
    python tools/convert_card_art.py
    python tools/convert_card_art.py --overrides "C:/path/to/more cards"

Writes into Assets/Resources/CardArt/:
    <catalog_id>.png            from img/cards/MSW/<catalog_id>-<slug>.webp
    hurt-feelings[-<skin>].png  from img/hurt-feelings[-<skin>].webp

unity-client/art-overrides/ holds the replacement card faces (our own art, replacing the web-static
set one card at a time) and is always used. --overrides DIR (repeatable) adds more folders takes replacement card images -- finished card faces named
<number>-<slug>.png (or .webp/.jpg), like "01-altruism.png" -- and uses them instead of the
web-static art for those card numbers. They are always re-converted.

Names are id-only so CardArtLibrary can look a card up without knowing its
slug. Images are resized up to a multiple-of-4 size so Unity can GPU-compress
them. Unchanged files are skipped, so re-running is cheap -- but delete
Assets/Resources/CardArt/ first if you change the conversion itself.
"""
import argparse
import re
import sys
from pathlib import Path

from PIL import Image

UNITY_CLIENT = Path(__file__).resolve().parent.parent
IMG_ROOT = UNITY_CLIENT.parent / "web-static" / "img"
OUT_DIR = UNITY_CLIENT / "Assets" / "Resources" / "CardArt"
OVERRIDES_DIR = UNITY_CLIENT / "art-overrides"

CARD_NAME = re.compile(r"^(\d+)-.+\.webp$")


def round_up_to_multiple_of_4(value: int) -> int:
    return (value + 3) // 4 * 4


def convert(source: Path, target: Path, force: bool = False) -> bool:
    if not force and target.exists() and target.stat().st_mtime >= source.stat().st_mtime:
        return False
    with Image.open(source) as image:
        image = image.convert("RGBA")
        # GPU texture compression (DXT, ETC2) needs both sides to be a
        # multiple of 4; the card art is 744x1039, and Unity silently leaves
        # anything else uncompressed -- 2.9 MB per card instead of ~0.7 MB,
        # 400 MB of textures across the set. Stretching by a pixel or two is
        # invisible.
        size = (round_up_to_multiple_of_4(image.width), round_up_to_multiple_of_4(image.height))
        if size != image.size:
            image = image.resize(size, Image.LANCZOS)
        image.save(target, "PNG", optimize=False, compress_level=6)
    return True


OVERRIDE_NAME = re.compile(r"^(\d+)-.+\.(png|webp|jpe?g)$", re.IGNORECASE)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--overrides", action="append", default=[], metavar="DIR", help="folder of replacement card images")
    args = parser.parse_args()

    if not IMG_ROOT.is_dir():
        print(f"Can't find {IMG_ROOT}", file=sys.stderr)
        return 1

    OUT_DIR.mkdir(parents=True, exist_ok=True)

    jobs = []
    for source in sorted((IMG_ROOT / "cards" / "MSW").glob("*.webp")):
        match = CARD_NAME.match(source.name)
        if match:
            jobs.append((source, OUT_DIR / f"{match.group(1)}.png"))
    for source in sorted(IMG_ROOT.glob("hurt-feelings*.webp")):
        jobs.append((source, OUT_DIR / f"{source.stem}.png"))

    # A replacement card face wins over the web-static one for the same card number.
    overridden = {}
    for folder in [OVERRIDES_DIR, *args.overrides]:
        if not Path(folder).is_dir():
            continue
        for source in sorted(Path(folder).iterdir()):
            match = OVERRIDE_NAME.match(source.name)
            if match:
                overridden[OUT_DIR / f"{int(match.group(1))}.png"] = source
    jobs = [(source, target) for source, target in jobs if target not in overridden]
    converted = sum(convert(source, target) for source, target in jobs)
    converted += sum(convert(source, target, force=True) for target, source in overridden.items())
    jobs += [(source, target) for target, source in overridden.items()]
    print(f"{len(jobs)} images: {converted} converted, {len(jobs) - converted} up to date -> {OUT_DIR}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

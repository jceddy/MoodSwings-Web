#!/usr/bin/env python3
"""Convert web-static's card art (.webp) to PNGs Unity can import.

Unity can't import WebP, and the art is already checked in under
web-static/img/, so this regenerates a Unity-friendly copy on demand rather
than committing a second set of (much larger) PNGs. Output is git-ignored.

    pip install pillow
    python tools/convert_card_art.py

Writes into Assets/Resources/CardArt/:
    <catalog_id>.png            from img/cards/MSW/<catalog_id>-<slug>.webp
    hurt-feelings[-<skin>].png  from img/hurt-feelings[-<skin>].webp

Names are id-only so CardArtLibrary can look a card up without knowing its
slug. Unchanged files are skipped, so re-running is cheap.
"""
import re
import sys
from pathlib import Path

from PIL import Image

UNITY_CLIENT = Path(__file__).resolve().parent.parent
IMG_ROOT = UNITY_CLIENT.parent / "web-static" / "img"
OUT_DIR = UNITY_CLIENT / "Assets" / "Resources" / "CardArt"

CARD_NAME = re.compile(r"^(\d+)-.+\.webp$")


def convert(source: Path, target: Path) -> bool:
    if target.exists() and target.stat().st_mtime >= source.stat().st_mtime:
        return False
    with Image.open(source) as image:
        image.convert("RGBA").save(target, "PNG", optimize=False, compress_level=6)
    return True


def main() -> int:
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

    converted = sum(convert(source, target) for source, target in jobs)
    print(f"{len(jobs)} images: {converted} converted, {len(jobs) - converted} up to date -> {OUT_DIR}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

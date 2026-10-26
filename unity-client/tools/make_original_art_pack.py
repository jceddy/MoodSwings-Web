#!/usr/bin/env python3
"""Rebuilds the "Original Mood Swings Art" art pack (art-packs/Original-Mood-Swings-Art.zip) from web-static's art.

    pip install pillow
    python tools/make_original_art_pack.py [--folder DIR]

The pack is the example for Settings > Card art: MSW/<number>-<name>.png for every card, plus back.png,
hurt-feelings.png and a README.txt describing the format (see "Art packs" in README.md). --folder also leaves the
unzipped folder at DIR (to look at, or to use as a pack as it is).
"""
import argparse
import re
import shutil
import sys
import tempfile
import zipfile
from pathlib import Path

from PIL import Image

UNITY_CLIENT = Path(__file__).resolve().parent.parent
REPO = UNITY_CLIENT.parent
SOURCE = REPO / "web-static" / "img"
ZIP = UNITY_CLIENT / "art-packs" / "Original-Mood-Swings-Art.zip"

README = """MOOD art pack format
====================

An art pack is a folder (or a .zip with the same layout) of PNG or JPG images. In the app:
Settings > Card art > Add a folder... (or Add a .zip...), then tap the pack to use it.

Layout
  MSW/12-faith.png      a card face, in the folder of its SET (MSW is Mood Swings, the only set so far)
  back.png              the back of a card (what a deck looks like) -- at the top, not in a set folder
  hurt-feelings.png     the Hurt Feelings card -- at the top too

Card file names: the card's NUMBER first (its catalog number, the same one the numbers in this pack use),
then anything you like: 12-faith.png, 012.jpg and 12.png all work. Set folders are named with the set's code in
capitals; images with no set folder are treated as MSW, so a small pack can skip the folder.

You do not need every card: a card the pack doesn't have keeps the art that comes with the app.
Only PNG and JPG work (not WebP). The card art that ships is 744 x 1040 (about 5:7); other sizes are
stretched to fit the card, and images taller than 1040 px are shrunk. Rounded corners can be
transparent (PNG).

Limits: 12 MB a file, 600 files and 400 MB a pack. Files that don't follow the naming (like this
README) are ignored.

This pack is the original Mood Swings art: all %d cards, the Hurt Feelings card and the card back.
"""


def build(folder: Path) -> int:
    (folder / "MSW").mkdir(parents=True, exist_ok=True)
    count = 0
    for source in sorted((SOURCE / "cards" / "MSW").glob("*.webp"), key=lambda p: int(p.name.split("-")[0])):
        match = re.match(r"^(\d+)-(.+)\.webp$", source.name)
        if not match:
            continue
        with Image.open(source) as image:
            image.convert("RGBA").save(folder / "MSW" / f"{match.group(1)}-{match.group(2)}.png", "PNG", compress_level=9)
        count += 1

    with Image.open(SOURCE / "hurt-feelings.webp") as image:
        image.convert("RGBA").save(folder / "hurt-feelings.png", "PNG", compress_level=9)
    with Image.open(UNITY_CLIENT / "Assets" / "Resources" / "UI" / "card-back.png") as image:
        image.convert("RGBA").save(folder / "back.png", "PNG", compress_level=9)
    (folder / "README.txt").write_text(README % count, encoding="utf-8")
    return count


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--folder", default=None, metavar="DIR", help="also leave the unzipped pack here")
    args = parser.parse_args()

    with tempfile.TemporaryDirectory() as temp:
        folder = Path(temp) / "pack"
        count = build(folder)
        ZIP.parent.mkdir(parents=True, exist_ok=True)
        if ZIP.exists():
            ZIP.unlink()
        with zipfile.ZipFile(ZIP, "w", zipfile.ZIP_DEFLATED) as archive:
            for file in sorted(folder.rglob("*")):
                if file.is_file():
                    archive.write(file, file.relative_to(folder).as_posix())
        if args.folder:
            target = Path(args.folder)
            target.mkdir(parents=True, exist_ok=True)
            for child in folder.iterdir():
                destination = target / child.name
                if destination.exists():
                    shutil.rmtree(destination) if destination.is_dir() else destination.unlink()
                shutil.move(str(child), str(destination))

    print(f"{count} cards + hurt feelings + back -> {ZIP} ({ZIP.stat().st_size // (1024 * 1024)} MB)")
    return 0


if __name__ == "__main__":
    sys.exit(main())

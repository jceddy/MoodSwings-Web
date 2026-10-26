#!/usr/bin/env python3
"""Builds the two example art packs: "Original Mood Swings Art" and "AI Assisted Mood Swings Art".

    python tools/make_art_packs.py --original "<folder>" --ai-source "<folder>" [--out DIR]

--original is the folder the original art lives in, already laid out as a pack (MSW/<number>-<name>.png, back.png,
hurt-feelings.png, README.txt -- see "Art packs" in README.md). It is zipped as it is, so whatever is in it (the card
back with its transparent corners, say) is what the pack gets.

--ai-source is the folder of finished card faces the app itself ships (the unity-client/art-overrides images, as the
PNGs they are made from): <number>-<name>.png for each card, card-back.png and hurt-feelings.png. It is laid out as a
pack in "AI Assisted Mood Swings Art" -- a card the folder lacks is left out of the pack, so it keeps the app's art.

--out is where the packs go (default: next to the original folder): each pack as a folder and as a .zip of the same name.
The zips are what to add in the app (Settings > Card art > Add a .zip...) or attach to a GitHub release.
"""
import argparse
import re
import shutil
import sys
import zipfile
from pathlib import Path

FORMAT = """MOOD art pack format
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

"""

ORIGINAL = "Original Mood Swings Art"
AI_ASSISTED = "AI Assisted Mood Swings Art"
CARD = re.compile(r"^(\d+)-.+\.png$", re.IGNORECASE)


def zip_pack(folder: Path, target: Path) -> int:
    """Zips the pack with its files at the top of the archive; returns how many files went in."""
    if target.exists():
        target.unlink()
    count = 0
    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as archive:
        for file in sorted(folder.rglob("*")):
            if file.is_file():
                archive.write(file, file.relative_to(folder).as_posix())
                count += 1
    return count


def build_ai_assisted(source: Path, folder: Path) -> int:
    """Lays the app's card art out as a pack in `folder`; returns how many cards."""
    # The old cards are removed one by one and the folders kept: a synced folder (OneDrive) can refuse to be deleted.
    (folder / "MSW").mkdir(parents=True, exist_ok=True)
    for old in (folder / "MSW").glob("*.png"):
        old.unlink()
    cards = sorted((f for f in source.iterdir() if CARD.match(f.name)), key=lambda f: int(f.name.split("-")[0]))
    for card in cards:
        shutil.copyfile(card, folder / "MSW" / card.name)
    for name, target in (("card-back.png", "back.png"), ("hurt-feelings.png", "hurt-feelings.png")):
        if not (source / name).is_file():
            raise SystemExit(f"{source} has no {name}")
        shutil.copyfile(source / name, folder / target)
    (folder / "README.txt").write_text(
        FORMAT
        + f"This pack is the AI-assisted Mood Swings art the app ships with: {len(cards)} cards, the Hurt Feelings card and the card back.\n",
        encoding="utf-8",
    )
    return len(cards)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--original", required=True, metavar="DIR", help="the original art, laid out as a pack")
    parser.add_argument("--ai-source", required=True, metavar="DIR", help="the finished card faces the app ships")
    parser.add_argument("--out", default=None, metavar="DIR", help="where the packs go (default: next to --original)")
    args = parser.parse_args()

    original = Path(args.original)
    source = Path(args.ai_source)
    out = Path(args.out) if args.out else original.parent
    for folder in (original, source):
        if not folder.is_dir():
            print(f"Can't find {folder}", file=sys.stderr)
            return 1
    if not (original / "back.png").is_file() or not (original / "MSW").is_dir():
        print(f"{original} doesn't look like a pack (no MSW/ folder or back.png)", file=sys.stderr)
        return 1

    out.mkdir(parents=True, exist_ok=True)
    zip_pack(original, out / f"{ORIGINAL}.zip")
    print(f"{ORIGINAL}: {sum(1 for _ in (original / 'MSW').glob('*.png'))} cards -> {out / (ORIGINAL + '.zip')}")

    cards = build_ai_assisted(source, out / AI_ASSISTED)
    zip_pack(out / AI_ASSISTED, out / f"{AI_ASSISTED}.zip")
    print(f"{AI_ASSISTED}: {cards} cards -> {out / (AI_ASSISTED + '.zip')}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

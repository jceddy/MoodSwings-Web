#!/usr/bin/env python3
"""Imports finished card art into the project: art-overrides/ (what the app ships) and the Unity copy of it.

    pip install pillow
    python tools/import_card_art.py "C:/path/to/folder"

The folder holds finished card faces named <number>-<slug>.png (like "1-altruism.png"), and may also hold
hurt-feelings.png (the Hurt Feelings card) and card-back.png (the back of a card). Each card becomes
art-overrides/<number>-<slug>.webp -- replacing the card's earlier image, whatever its slug -- the Hurt Feelings card
art-overrides/hurt-feelings.webp, and the card back Assets/Resources/UI/card-back.png. Then convert_card_art.py runs, so
the game has the new art at once. Commit art-overrides/ (and the card back) afterwards.
"""
import re
import shutil
import sys
from pathlib import Path

from PIL import Image

UNITY_CLIENT = Path(__file__).resolve().parent.parent
OVERRIDES = UNITY_CLIENT / "art-overrides"
CARD_BACK = UNITY_CLIENT / "Assets" / "Resources" / "UI" / "card-back.png"
EXPECTED_SIZE = (744, 1040)
QUALITY = 92  # WebP quality of the art in art-overrides


def save_webp(source: Path, target: Path) -> None:
    with Image.open(source) as image:
        if image.size != EXPECTED_SIZE:
            print(f"  note: {source.name} is {image.width}x{image.height}, not {EXPECTED_SIZE[0]}x{EXPECTED_SIZE[1]}")
        image.convert("RGBA").save(target, "WEBP", quality=QUALITY, method=6)


def main() -> int:
    if len(sys.argv) != 2 or not Path(sys.argv[1]).is_dir():
        print(__doc__)
        return 1

    source_folder = Path(sys.argv[1])
    cards = [f for f in sorted(source_folder.iterdir()) if re.match(r"^\d+-.+\.png$", f.name, re.IGNORECASE)]
    for card in cards:
        number = int(card.name.split("-")[0])
        for older in OVERRIDES.glob(f"{number}-*.webp"):
            older.unlink()  # the same card under another slug: only one image per number
        save_webp(card, OVERRIDES / (card.stem + ".webp"))
    print(f"{len(cards)} cards imported")

    hurt = source_folder / "hurt-feelings.png"
    if hurt.is_file():
        save_webp(hurt, OVERRIDES / "hurt-feelings.webp")
        print("hurt-feelings imported")
    back = source_folder / "card-back.png"
    if back.is_file():
        shutil.copyfile(back, CARD_BACK)
        print("card-back imported")

    sys.path.insert(0, str(Path(__file__).resolve().parent))
    import convert_card_art
    sys.argv = ["convert_card_art.py"]
    return convert_card_art.main()


if __name__ == "__main__":
    sys.exit(main())

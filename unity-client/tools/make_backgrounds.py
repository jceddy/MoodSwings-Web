#!/usr/bin/env python3
"""Make the client's table backgrounds (a light and a dark wood) from the full-size originals.

    pip install pillow
    python tools/make_backgrounds.py "C:/Users/me/Pictures/MoodSwings"

The folder given holds light-background.jpg and dark-background.jpg. They are shrunk to 1920 px wide (the
board is laid out at 1920 x 1080 and scaled from there) and saved under Assets/Resources/Backgrounds/. The
height comes out a multiple of 4, so Unity can GPU-compress them.
"""
import sys
from pathlib import Path

from PIL import Image

OUT = Path(__file__).resolve().parent.parent / "Assets" / "Resources" / "Backgrounds"
WIDTH = 1920


def main() -> int:
    if len(sys.argv) != 2:
        print(__doc__)
        return 1
    source = Path(sys.argv[1])
    OUT.mkdir(parents=True, exist_ok=True)
    for name in ("light-background", "dark-background"):
        with Image.open(source / f"{name}.jpg") as image:
            image = image.convert("RGB")
            height = round(image.height * WIDTH / image.width / 4) * 4
            image = image.resize((WIDTH, height), Image.LANCZOS)
            target = OUT / f"{name}.jpg"
            image.save(target, "JPEG", quality=86, optimize=True, progressive=True)
            print(f"{target.name}: {image.size[0]}x{image.size[1]}, {target.stat().st_size // 1024} KB")
    return 0


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""Generate the app icons under Assets/Icons/: the back of a Mood Swings card.

A black card with the four color corners (blue, purple, green and red, in wavy stripes with a little sun in each
corner) around a white diamond with a hand-lettered M in it, like the card back the game is played with.

    pip install pillow
    python tools/make_icons.py

Writes:
    icon-1024.png         full-bleed square icon (also the source for the Windows .ico)
    android-background.png / android-foreground.png   the adaptive icon's two layers (432x432; the
                          foreground keeps its art inside the central 66% so any mask shape works)
    MOOD.ico              multi-size Windows icon (used by the installer and the .exe)
    Assets/Plugins/Android/MoodPush.androidlib/res/drawable-*/ic_stat_mood.png   the small icon on Android's notification
                          bar: a diamond with the M cut out of it (see notification_icon())

To use real art instead, replace icon-1024.png (and the two android-* layers) with your own and run
only the .ico step: python tools/make_icons.py --from-icon
"""
import argparse
import math
import random
import sys
from pathlib import Path

from PIL import Image, ImageDraw

OUT = Path(__file__).resolve().parent.parent / "Assets" / "Icons"
ANDROID_RES = Path(__file__).resolve().parent.parent / "Assets" / "Plugins" / "Android" / "MoodPush.androidlib" / "res"

# The notification small icon at each screen density: 24dp square.
NOTIFICATION_ICON_SIZES = {"mdpi": 24, "hdpi": 36, "xhdpi": 48, "xxhdpi": 72, "xxxhdpi": 96}

BLACK = (12, 12, 14, 255)
WHITE = (250, 249, 245, 255)
BLUE = (28, 132, 232, 255)
PURPLE = (126, 56, 206, 255)
GREEN = (24, 160, 84, 255)
RED = (216, 52, 42, 255)

SCALE = 2  # drawn at twice the size and shrunk, for smooth edges


def stroke(draw, points, width, color):
    """A thick line through closely spaced points, stamped as discs so it has no ragged joints."""
    r = width / 2
    for x, y in points:
        draw.ellipse([x - r, y - r, x + r, y + r], fill=color)


def wavy(draw, origin, along, normal, length, offset, amplitude, wavelength, width, color):
    """A stripe `offset` out from `origin` (the middle of an edge), running `length` each way along it, wobbling."""
    points = []
    steps = max(24, int(length / 2))
    for i in range(steps + 1):
        u = -length + 2 * length * i / steps
        out = offset + amplitude * math.sin(2 * math.pi * u / wavelength)
        points.append((origin[0] + along[0] * u + normal[0] * out, origin[1] + along[1] * u + normal[1] * out))
    stroke(draw, points, width, color)


def corners(size, d):
    """The black card with its four color corners, for a diamond whose half-diagonal is `d` (a fraction of size)."""
    s = size * SCALE
    c = s / 2
    d *= s
    frame = 0.05 * s
    img = Image.new("RGBA", (s, s), BLACK)
    r2 = math.sqrt(2)

    # (color, direction out of the middle of that corner's edge of the diamond, quadrant it lives in)
    for color, (nx, ny), (qx, qy) in ((BLUE, (-1, -1), (0, 0)), (PURPLE, (1, -1), (1, 0)),
                                       (GREEN, (-1, 1), (0, 1)), (RED, (1, 1), (1, 1))):
        layer = Image.new("RGBA", (s, s), (0, 0, 0, 0))
        draw = ImageDraw.Draw(layer)
        normal = (nx / r2, ny / r2)
        along = (-ny / r2, nx / r2)
        origin = (c + nx * d / 2, c + ny * d / 2)
        gap = 0.075 * s
        stripe = 0.030 * s
        for k in range(8):
            wavy(draw, origin, along, normal, d * 0.9, gap + k * 0.058 * s, 0.012 * s, 0.11 * s, stripe, color)

        # A little sun in the corner: a ring and a dot.
        sx = frame + 0.075 * s if qx == 0 else s - frame - 0.075 * s
        sy = frame + 0.075 * s if qy == 0 else s - frame - 0.075 * s
        backing = 0.058 * s
        draw.ellipse([sx - backing, sy - backing, sx + backing, sy + backing], fill=BLACK)
        ring = 0.036 * s
        draw.ellipse([sx - ring, sy - ring, sx + ring, sy + ring], outline=color, width=int(0.011 * s))
        dot = 0.014 * s
        draw.ellipse([sx - dot, sy - dot, sx + dot, sy + dot], fill=color)

        # Only this corner's quarter, inside the black frame.
        mask = Image.new("L", (s, s), 0)
        mdraw = ImageDraw.Draw(mask)
        x0, x1 = (frame, c) if qx == 0 else (c, s - frame)
        y0, y1 = (frame, c) if qy == 0 else (c, s - frame)
        mdraw.rectangle([x0, y0, x1, y1], fill=255)
        img.paste(layer, (0, 0), Image.composite(layer.split()[3], Image.new("L", (s, s), 0), mask))
    return img.resize((size, size), Image.LANCZOS)


def diamond_with_m(size, d, with_corners_behind=None):
    """The white diamond, ragged at the edge, with a stylized M in it, on a transparent square (or on `with_corners_behind`)."""
    s = size * SCALE
    c = s / 2
    d *= s
    layer = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)
    draw.polygon([(c - d, c), (c, c - d), (c + d, c), (c, c + d)], fill=WHITE)

    # Ink splatter along the edge, like the printed card back.
    rng = random.Random(7)
    for _ in range(int(260 * (s / 2048) + 160)):
        edge = rng.randrange(4)
        t = rng.random()
        ax, ay = ((c - d, c), (c, c - d), (c + d, c), (c, c + d))[edge]
        bx, by = ((c, c - d), (c + d, c), (c, c + d), (c - d, c))[edge]
        x, y = ax + (bx - ax) * t, ay + (by - ay) * t
        # Inward from the edge, mostly close to it.
        inward = rng.random() ** 2.2 * 0.09 * s
        nx, ny = c - x, c - y
        norm = math.hypot(nx, ny)
        x, y = x + nx / norm * inward, y + ny / norm * inward
        r = rng.random() ** 2 * 0.007 * s + 0.0015 * s
        draw.ellipse([x - r, y - r, x + r, y + r], fill=BLACK)

    # The M: one wobbling stroke, left foot up to the left shoulder, down to the middle, up to the right shoulder, down.
    m = [(-0.44, 0.40), (-0.47, -0.36), (0.0, 0.10), (0.47, -0.36), (0.44, 0.40)]
    pts = []
    for (ax, ay), (bx, by) in zip(m, m[1:]):
        for i in range(0, 60):
            t = i / 60
            x = ax + (bx - ax) * t
            y = ay + (by - ay) * t
            wob = 0.018 * math.sin(2 * math.pi * (x + y) * 1.7)
            pts.append((c + (x + wob) * d * 0.98, c + (y - wob) * d * 0.90))
    pts.append((c + m[-1][0] * d * 0.98, c + m[-1][1] * d * 0.90))
    stroke(draw, pts, 0.17 * d, BLACK)
    # A small flourish under it, a short wavy rule.
    rule = [(c + (-0.30 + 0.6 * i / 80) * d, c + 0.64 * d + 0.012 * s * math.sin(i * 0.18)) for i in range(81)]
    stroke(draw, rule, 0.045 * d, BLACK)

    layer = layer.resize((size, size), Image.LANCZOS)
    if with_corners_behind is not None:
        with_corners_behind.alpha_composite(layer)
        return with_corners_behind
    return layer


def notification_icon(size):
    """
    The small icon for Android's notification bar. Android draws only the shape of such an icon -- every pixel with any alpha
    becomes white, whatever its color -- so the card back would be just a solid square or circle. This is a diamond with the M
    cut out of it as transparent, which gives it a profile of its own among the other apps' circles. The M is drawn larger and
    plainer than on the card (no wobble, no ink splatter, no flourish): at 24 pixels the fine detail would only be noise.
    """
    s = size * 4
    c = s / 2
    d = 0.47 * s
    img = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)
    draw.polygon([(c - d, c), (c, c - d), (c + d, c), (c, c + d)], fill=(255, 255, 255, 255))

    # The M, in the same shape as on the card back, scaled in so its strokes leave white around its ends; erased, not painted.
    m = [(-0.44, 0.40), (-0.47, -0.36), (0.0, 0.10), (0.47, -0.36), (0.44, 0.40)]
    pts = []
    for (ax, ay), (bx, by) in zip(m, m[1:]):
        for i in range(0, 80):
            t = i / 80
            pts.append((c + (ax + (bx - ax) * t) * d * 0.80, c + (ay + (by - ay) * t) * d * 0.80))
    pts.append((c + m[-1][0] * d * 0.80, c + m[-1][1] * d * 0.80))
    stroke(draw, pts, 0.20 * d, (0, 0, 0, 0))
    return img.resize((size, size), Image.LANCZOS)


def make_notification_icons() -> None:
    for density, size in NOTIFICATION_ICON_SIZES.items():
        folder = ANDROID_RES / f"drawable-{density}"
        folder.mkdir(parents=True, exist_ok=True)
        notification_icon(size).save(folder / "ic_stat_mood.png")


def make_icons() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    make_notification_icons()

    # The full icon: the whole card back, the diamond filling most of it.
    full = corners(1024, 0.40)
    diamond_with_m(1024, 0.40, with_corners_behind=full)
    full.save(OUT / "icon-1024.png")

    # The adaptive icon: the corners are the background layer and the diamond the foreground, which stays inside the
    # central 66% so any shape of mask leaves it whole.
    corners(432, 0.27).save(OUT / "android-background.png")
    diamond_with_m(432, 0.27).save(OUT / "android-foreground.png")


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

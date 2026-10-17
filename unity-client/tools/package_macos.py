#!/usr/bin/env python3
"""Packages the macOS player (Build/macOS/MOOD.app, from MoodSwings.Editor.MacBuild.Build) for testers.

    python tools/package_macos.py [--version 1.0.0]

Writes Build/macOS/MOOD-<version>-mac.zip. A plain Windows zip of the .app would not work on a Mac: Unity builds it on
Windows without the "executable" permission on the program inside, so it would not start. This writes the zip with
those permissions set (the program, the libraries and the plugins' executables), and adds a short note on opening an
app that has not been signed by Apple (see README.md, "macOS").

If rcodesign (https://github.com/indygreg/apple-platform-rs) is on the PATH, the app is first signed ad hoc with it,
which an Apple-silicon (universal) build needs in order to run at all. That is not notarization and does not remove
the "unidentified developer" prompt; only an Apple Developer ID can.
"""
import argparse
import re
import shutil
import subprocess
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
APP = ROOT / "Build" / "macOS" / "MOOD.app"

NOTE = """MOOD for macOS
==============

This app is not signed by Apple, so macOS stops it the first time:

  1. Unzip it and drag MOOD.app to your Applications folder (or anywhere).
  2. Open it. macOS says it "cannot be opened because the developer cannot be verified".
  3. Open  System Settings > Privacy & Security, scroll down, and click "Open Anyway" next to MOOD.
     (On macOS 14 and earlier you can instead right-click MOOD.app and choose Open.)
  4. Confirm. It opens normally from then on.

If macOS says the app is "damaged", open Terminal and run:

  xattr -dr com.apple.quarantine /path/to/MOOD.app

(An Intel build runs on Apple-silicon Macs through Rosetta; macOS offers to install it the first time.)
"""


def project_version() -> str:
    text = (ROOT / "ProjectSettings" / "ProjectSettings.asset").read_text(encoding="utf-8")
    match = re.search(r"^\s*bundleVersion:\s*(.+)$", text, re.MULTILINE)
    return match.group(1).strip() if match else "0.0.0"


def is_executable(path: Path) -> bool:
    relative = path.relative_to(APP).as_posix()
    if "/MacOS/" in f"/{relative}":
        return True
    return path.suffix in (".dylib", ".so", ".bundle")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--version", default=None)
    args = parser.parse_args()

    if not (APP / "Contents" / "MacOS").is_dir():
        print(f"No build at {APP} -- build it first (MoodSwings > Build macOS Player, or the -executeMethod in README.md).", file=sys.stderr)
        return 1

    rcodesign = shutil.which("rcodesign")
    if rcodesign:
        subprocess.run([rcodesign, "sign", str(APP)], check=True)
        print("Signed ad hoc with rcodesign.")
    else:
        print("Not signed (rcodesign isn't installed): fine for an Intel build, which Macs run unsigned.")

    version = args.version or project_version()
    target = APP.parent / f"MOOD-{version}-mac.zip"
    if target.exists():
        target.unlink()

    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as archive:
        note = zipfile.ZipInfo("OPEN ME FIRST.txt")
        note.create_system = 3
        note.external_attr = 0o100644 << 16
        archive.writestr(note, NOTE)
        for path in sorted(APP.rglob("*")):
            name = f"{APP.name}/{path.relative_to(APP).as_posix()}"
            if path.is_dir():
                info = zipfile.ZipInfo(name + "/")
                info.external_attr = (0o40755 << 16) | 0x10
                archive.writestr(info, b"")
                continue
            info = zipfile.ZipInfo.from_file(path, name)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3  # Unix, so the permission bits below are honored
            info.external_attr = ((0o100755 if is_executable(path) else 0o100644) << 16)
            archive.writestr(info, path.read_bytes())

    print(f"Zip: {target} ({target.stat().st_size // (1024 * 1024)} MB)")
    return 0


if __name__ == "__main__":
    sys.exit(main())

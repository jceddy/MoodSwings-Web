#!/usr/bin/env python3
"""Writes the update manifest (latest.json) for the builds in Build/, and optionally uploads them.

The apps look at one fixed address (UpdateConfig.ManifestUrl in Assets/Scripts/Core/UpdateFlow.cs) to see whether a
newer version exists. That file names the newest version and, for each platform, where its download is and its SHA-256
(the Windows app checks the installer against it before running it).

    python tools/publish_client.py                    # write Build/latest.json for the current version, change nothing else
    python tools/publish_client.py --note "Music" --note "Art packs"
    python tools/publish_client.py --upload           # also upload the builds and latest.json to the client-latest release

Uploading needs the GitHub CLI (gh), signed in. It replaces the files on the release `client-latest` (creating it if
there is none), so that the manifest's address never changes. Nothing is uploaded without --upload.

Which files are published (a platform without its file is left out of the manifest, so its app is never told to update):
    windows  Build/Windows/MOOD-Setup-<version>.exe     (tools/package_windows.ps1)
    macos    Build/macOS/MOOD-<version>-mac.zip         (tools/package_macos.py)
    android  Build/Android/MOOD.apk                     (MoodSwings.Editor.AndroidBuild.Build)
"""
import argparse
import hashlib
import json
import re
import subprocess
import sys
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TAG = "client-latest"
REPO = "jceddy/MoodSwings-Web"


def app_version():
    text = (ROOT / "ProjectSettings" / "ProjectSettings.asset").read_text(encoding="utf-8")
    match = re.search(r"^\s*bundleVersion:\s*(\S+)", text, re.MULTILINE)
    if not match:
        sys.exit("Couldn't find bundleVersion in ProjectSettings.asset.")
    return match.group(1)


def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1 << 20), b""):
            digest.update(block)
    return digest.hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--note", action="append", default=[], help="a line of what is new (repeat for more; keep it short)")
    parser.add_argument("--upload", action="store_true", help="upload the builds and latest.json to the client-latest release")
    args = parser.parse_args()

    version = app_version()
    base = f"https://github.com/{REPO}/releases/download/{TAG}/"
    builds = {
        "windows": ROOT / "Build" / "Windows" / f"MOOD-Setup-{version}.exe",
        "macos": ROOT / "Build" / "macOS" / f"MOOD-{version}-mac.zip",
        "android": ROOT / "Build" / "Android" / "MOOD.apk",
    }

    manifest = {"version": version, "released": date.today().isoformat(), "notes": args.note}
    files = []
    for platform, path in builds.items():
        if not path.exists():
            print(f"  {platform}: no {path.name} -- left out")
            continue
        # The apk keeps one name, so the version goes in the name it is published under.
        published = f"MOOD-{version}.apk" if platform == "android" else path.name
        manifest[platform] = {"url": base + published, "sha256": sha256(path)}
        files.append((path, published))
        print(f"  {platform}: {published}  {manifest[platform]['sha256'][:16]}...")

    if not files:
        sys.exit("Nothing to publish: build at least one platform first.")

    out = ROOT / "Build" / "latest.json"
    out.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    print(f"Wrote {out}")

    if not args.upload:
        print("Not uploaded (add --upload to put these on the client-latest release).")
        return

    exists = subprocess.run(["gh", "release", "view", TAG, "--repo", REPO], capture_output=True).returncode == 0
    if not exists:
        subprocess.run(["gh", "release", "create", TAG, "--repo", REPO, "--title", "Client downloads",
                        "--notes", "The newest client builds. The apps check latest.json here for updates."], check=True)

    # gh names an uploaded asset after its file, so copies carry the published names.
    staging = ROOT / "Build" / "publish"
    staging.mkdir(exist_ok=True)
    uploads = []
    for path, published in files:
        copy = staging / published
        copy.write_bytes(path.read_bytes())
        uploads.append(str(copy))
    subprocess.run(["gh", "release", "upload", TAG, "--repo", REPO, "--clobber", *uploads], check=True)
    # The manifest goes in a call of its own, after the files: gh sends the files of one call at the same time, so the
    # small manifest would land first, and an app would be told about a version whose downloads aren't there yet.
    subprocess.run(["gh", "release", "upload", TAG, "--repo", REPO, "--clobber", str(out)], check=True)
    print("Uploaded.")


if __name__ == "__main__":
    main()

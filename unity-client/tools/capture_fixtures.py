#!/usr/bin/env python3
"""Capture real API responses as JSON fixtures for the Unity client's tests.

Logs in to a MoodSwings server and saves the responses of a handful of
read-only routes under Assets/Tests/Fixtures/, so model classes can be
written and tested against what the server really sends rather than against
guesses from reading PHP.

Credentials come from the environment (never the command line, so they stay
out of shell history). Use a throwaway dev-server account. Set them in the
same shell you run the script from:

    PowerShell:  $env:MOODSWINGS_USER = "..."; $env:MOODSWINGS_PASSWORD = "..."
    Git Bash:    export MOODSWINGS_USER=... MOODSWINGS_PASSWORD=...
    cmd.exe:     set MOODSWINGS_USER=...   (and likewise MOODSWINGS_PASSWORD)

    python tools/capture_fixtures.py
    python tools/capture_fixtures.py --game-id 123 --game-id 456   # also capture game state

Only GET routes are called after the login. "email" and "phone_number"
values are redacted before anything is written.
"""
import argparse
import http.cookiejar
import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

UNITY_CLIENT = Path(__file__).resolve().parent.parent
OUT_DIR = UNITY_CLIENT / "Assets" / "Tests" / "Fixtures"
DEFAULT_SITE = "https://moodswings-dev.jceddy.com"
USER_AGENT = "MoodSwingsFixtureCapture/0.1"

REDACTED_KEYS = {"email", "phone_number"}

# (fixture name, path) -- all read-only.
STATIC_ROUTES = [
    ("me", "/me"),
    ("cards_catalog", "/cards/catalog"),
    ("decklists", "/decklists"),
    ("open_games", "/open-games"),
    ("open_games_mine", "/open-games?mine=1"),
    ("games", "/games"),
    ("games_past", "/games/past"),
    ("games_bots", "/games/bots"),
    ("friends", "/friends"),
    ("friends_invites", "/friends/invites"),
    ("synchronous_mode_enabled", "/config/synchronous-mode-enabled"),
]

GAME_ROUTES = [
    ("game_{id}_state", "/games/state?game_id={id}"),
    ("game_{id}_log", "/games/log?game_id={id}"),
]


def redact(value):
    if isinstance(value, dict):
        return {k: ("<redacted>" if k in REDACTED_KEYS and v else redact(v)) for k, v in value.items()}
    if isinstance(value, list):
        return [redact(v) for v in value]
    return value


class Client:
    def __init__(self, site):
        self.api = site.rstrip("/") + "/app"
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(self, path, body=None):
        data = json.dumps(body).encode() if body is not None else None
        # The host's filter answers 406 to Python's default User-Agent
        # (and to a bare "Mozilla/5.0"), so identify ourselves explicitly.
        req = urllib.request.Request(
            self.api + path,
            data=data,
            headers={"Content-Type": "application/json", "User-Agent": USER_AGENT},
            method="POST" if body is not None else "GET",
        )
        try:
            with self.opener.open(req, timeout=30) as response:
                return response.status, json.load(response)
        except urllib.error.HTTPError as error:
            try:
                return error.code, json.load(error)
            except ValueError:
                return error.code, None


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--site", default=DEFAULT_SITE)
    parser.add_argument("--game-id", type=int, action="append", default=[], help="also capture this game's state/log (repeatable)")
    args = parser.parse_args()

    user, password = os.environ.get("MOODSWINGS_USER"), os.environ.get("MOODSWINGS_PASSWORD")
    if not user or not password:
        print("Set MOODSWINGS_USER and MOODSWINGS_PASSWORD first.", file=sys.stderr)
        return 1

    client = Client(args.site)
    status, _ = client.request("/login", {"username": user, "password": password})
    if status != 200:
        print(f"Login failed (HTTP {status}).", file=sys.stderr)
        return 1

    OUT_DIR.mkdir(parents=True, exist_ok=True)

    routes = list(STATIC_ROUTES)
    for game_id in args.game_id:
        routes += [(name.format(id=game_id), path.format(id=game_id)) for name, path in GAME_ROUTES]

    for name, path in routes:
        status, body = client.request(path)
        if status != 200 or body is None:
            print(f"  skipped {path} (HTTP {status})")
            continue
        (OUT_DIR / f"{name}.json").write_text(json.dumps(redact(body), indent=2, sort_keys=True) + "\n", encoding="utf-8")
        print(f"  saved   {name}.json")

    client.request("/logout", {})
    return 0


if __name__ == "__main__":
    sys.exit(main())

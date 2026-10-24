#!/usr/bin/env python3
"""Writes Assets/Resources/Tour/sample-cards.json: the cards the welcome tour's sample game uses.

The tour's sample game (Assets/Scripts/Core/SampleGame.cs) is drawn from data, not from a server, so it needs each card's art
key, color, values and rules text. They are copied from the card catalog fixture (Assets/Tests/Fixtures/cards_catalog.json,
a capture of the server's own catalog). Add a card here when the sample game starts to use it.

    python tools/make_tour_cards.py
"""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CATALOG = ROOT / "Assets" / "Tests" / "Fixtures" / "cards_catalog.json"
OUT = ROOT / "Assets" / "Resources" / "Tour" / "sample-cards.json"

CARDS = [
    "Laziness", "Charity", "Superiority", "Serenity", "Rage", "Harmony", "Betrayal",
    "Indifference", "Cheer", "Doubt", "Kindness", "Meekness", "Self-Loathing", "Panic",
]


def main():
    catalog = json.loads(CATALOG.read_text(encoding="utf-8"))["cards"]
    by_name = {}
    for card in catalog:
        by_name.setdefault(card["name"], card)

    out = []
    for name in CARDS:
        card = by_name[name]
        out.append({
            "name": name,
            "catalog_card_id": card["catalog_card_id"],
            "color": card["color"],
            "base_value": card["base_value"],
            "alt_value": card["alt_value"],
            "rules_text": card["rules_text"],
        })

    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps({"cards": out}, indent=2) + "\n", encoding="utf-8")
    print(f"{len(out)} cards -> {OUT}")


if __name__ == "__main__":
    main()

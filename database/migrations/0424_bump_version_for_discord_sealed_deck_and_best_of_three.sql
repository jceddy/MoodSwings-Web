-- Discord client: Sealed Deck (deck-building screens, suggested deck,
-- build-by-modal, keep-previous-deck) and best-of-three for Practice,
-- Friend and Power Duel games. Pure code change; no schema change.
UPDATE schema_version SET version = '1.59.0' WHERE id = 1;

-- Discord: a card with two choice fields of its own (Regret, Faith, Guile,
-- ...) stays playable when a choice of play grants adds a third field --
-- Regret used to say "Needs the web app to play" in that situation. Pure
-- code change; no schema change.
UPDATE schema_version SET version = '1.61.1' WHERE id = 1;

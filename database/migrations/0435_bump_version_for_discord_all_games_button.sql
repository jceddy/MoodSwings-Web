-- Discord: an "All Games" button on every screen when the player has 2+
-- active games, returning to the game picker. Pure code change; no schema
-- change.
UPDATE schema_version SET version = '1.60.3' WHERE id = 1;

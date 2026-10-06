-- Bots now aim Hate, Anger and Shock at an opponent's Validation before
-- higher-valued moods, unless that would lose them the game. Pure code
-- change; no schema change.
UPDATE schema_version SET version = '1.61.5' WHERE id = 1;

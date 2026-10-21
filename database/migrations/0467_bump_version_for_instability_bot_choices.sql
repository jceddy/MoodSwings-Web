-- Bots now answer Instability by the lowest point swing (keeping powerful
-- while-in-play moods unless that loses the game) instead of giving up their
-- highest-value mood / a 0-value Hope. No schema change -- version bump only.
UPDATE schema_version SET version = '1.62.16' WHERE id = 1;

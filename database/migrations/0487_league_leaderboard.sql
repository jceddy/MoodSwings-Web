-- League: leaderboard, and no Rematch button on League games (no schema change).
UPDATE schema_version SET version = '1.63.5' WHERE id = 1;

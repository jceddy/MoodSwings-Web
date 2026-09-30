-- PuzzleOpponent no longer appears in the New Game practice-bot pickers
-- (web and Discord). Pure code change; no schema change.
UPDATE schema_version SET version = '1.59.1' WHERE id = 1;

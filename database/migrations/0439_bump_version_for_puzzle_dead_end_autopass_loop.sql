-- A puzzle attempt with no legal plays left is no longer auto-passed (and
-- refreshed) in a loop by advanceAutomatedTurns(). Pure code change; no
-- schema change.
UPDATE schema_version SET version = '1.61.2' WHERE id = 1;

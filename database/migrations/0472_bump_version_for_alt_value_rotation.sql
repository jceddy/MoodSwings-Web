-- The web client now rotates a "while in play" mood 180 degrees while its
-- alternate value is in effect (Enjoyment, Discipline, ...), the same as a mood
-- whose value an "after playing this mood" trigger has locked in. The server
-- exposes it as a new per-mood flag, alt_value_active. No schema change --
-- version bump only.
UPDATE schema_version SET version = '1.62.21' WHERE id = 1;

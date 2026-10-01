-- Betrayal now chooses which of your moods to give away up front (itself
-- included, via the schema's includes_self flag) instead of after entering
-- play. The old deferred decision still resolves for games caught mid-way
-- through it. Pure code change; no schema change.
UPDATE schema_version SET version = '1.59.6' WHERE id = 1;

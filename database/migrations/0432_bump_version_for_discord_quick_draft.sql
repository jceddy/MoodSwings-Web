-- Quick Draft (2 players) is now playable from the Discord client:
-- pool/opponent menus, a per-pile pick screen, then the shared
-- deck-building screens. Pure code change; no schema change.
UPDATE schema_version SET version = '1.60.0' WHERE id = 1;

-- Grid Draft (2 players) is now playable from the Discord client: pool/
-- opponent menus, a grid picture with numbered row/column arrows, a
-- row/column select, a drafted-cards view, then the shared deck-building
-- screens. Pure code change; no schema change.
UPDATE schema_version SET version = '1.61.0' WHERE id = 1;

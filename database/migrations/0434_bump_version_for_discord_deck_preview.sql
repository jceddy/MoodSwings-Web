-- Discord Sealed Deck / Quick Draft: "Preview suggested deck" shows the
-- suggested deck before committing to it. Pure code change; no schema change.
UPDATE schema_version SET version = '1.60.2' WHERE id = 1;

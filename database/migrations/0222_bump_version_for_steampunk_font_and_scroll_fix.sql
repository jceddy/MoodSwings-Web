-- Version-only bump covering Steampunk's own IM Fell English SC heading
-- font and the fixed-pseudo-element fix for themed-background scroll
-- jank (background-attachment: fixed repainting every scroll frame
-- instead of compositing). Neither touches the schema, so no other
-- statement is needed here -- just the patch bump per CLAUDE.md's own
-- versioning convention.
UPDATE schema_version SET version = '1.30.2' WHERE id = 1;

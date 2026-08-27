-- Bumps schema_version for moving Sealed Deck's own New Game dialog
-- presentation to a top-level Format dropdown option (issue #392 follow-up)
-- instead of one of "Draft" format's own Deck dropdown choices -- a UI-only
-- change (no new format value, no schema change), so no migration content
-- beyond this version stamp is needed, same pattern 0205/.../0212 already
-- used for their own code-only ships. A UI adjustment like this one bumps
-- the PATCH version only, per CLAUDE.md's own versioning convention -- it's
-- not a new full feature, unlike Sealed Deck itself (1.29.0).
UPDATE schema_version SET version = '1.29.1' WHERE id = 1;

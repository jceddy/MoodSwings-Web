-- Bumps schema_version for sorting a draft deck type's deck-building card
-- picker (#draft-deck-picker, renderDraftDeckBuilding()) by color/rarity/
-- name -- the same order openDraftPoolView()'s own "View draft pool"
-- sections already use -- instead of leaving it in draft/deal order. This
-- is a UI-only change (no schema change), so no migration content beyond
-- this version stamp is needed. A UI adjustment like this one bumps the
-- PATCH version only, per CLAUDE.md's own versioning convention -- it's
-- not a new full feature.
UPDATE schema_version SET version = '1.29.3' WHERE id = 1;

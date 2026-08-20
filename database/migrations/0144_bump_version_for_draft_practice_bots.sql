-- Issue #359: practice bots now play every draft-based deck_type
-- (quick_draft/winston_draft/grid_draft/rotisserie_draft/
-- tiered_rotisserie_draft), regardless of format -- drafting was the
-- last deck_type family bots couldn't be seated in at all. No schema
-- change of its own (migration 0143 already added the
-- draft_priority_score/card_synergy_partners data this relies on) --
-- this migration exists purely to keep schema_version in sync with the
-- VERSION bump, the same way 0024/.../0140 already did for their own
-- schema-less changes.
UPDATE schema_version SET version = '1.28.0' WHERE id = 1;

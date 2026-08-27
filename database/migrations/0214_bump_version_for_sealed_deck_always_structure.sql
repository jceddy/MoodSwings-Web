-- Bumps schema_version for removing Sealed Deck's own pool-source choice
-- entirely -- it's now always a Structure-deck-style pool
-- (buildSealedDeckPlayerPool() calls buildStructureDeckCardIds() directly),
-- rather than one of six sources picked at creation time. A UI/behavior
-- simplification like this one bumps the PATCH version only, per
-- CLAUDE.md's own versioning convention -- it's not a new full feature.
UPDATE schema_version SET version = '1.29.2' WHERE id = 1;

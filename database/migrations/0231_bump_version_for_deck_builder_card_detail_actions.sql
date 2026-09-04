-- Deck Builder (issue #93 follow-up): the catalog's own "+ Deck"/"+
-- Sideboard" actions moved from under each thumbnail into the shared
-- card-detail popup, each now paired with its own copy-count indicator
-- on its own line under the card image. A UI adjustment, not a new
-- schema/feature, so this only bumps the patch version per CLAUDE.md's
-- own versioning convention.
UPDATE schema_version SET version = '1.32.2' WHERE id = 1;

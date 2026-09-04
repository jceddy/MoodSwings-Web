-- Issue #90: best-of-three matches for Duel/Open Team Play/Closed Team
-- Play (game_matches, migration 0223) is a new feature/game mode -- see
-- CLAUDE.md's own versioning convention -- so this bumps the minor
-- version and resets patch to 0, rather than a plain patch bump.
UPDATE schema_version SET version = '1.31.0' WHERE id = 1;

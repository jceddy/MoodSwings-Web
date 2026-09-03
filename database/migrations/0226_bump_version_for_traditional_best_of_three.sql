-- Issue #90 follow-up: extends best-of-three matches to Traditional
-- (format: 'standard', at exactly 2 players -- migration 0225) and fixes
-- the open-lobby "Best of three" checkbox being a silent no-op for every
-- format. A tweak to an already-shipped feature, not a new one of its
-- own, so per CLAUDE.md's own versioning convention this only bumps the
-- patch component.
UPDATE schema_version SET version = '1.31.1' WHERE id = 1;

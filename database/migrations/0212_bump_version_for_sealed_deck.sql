-- Bumps schema_version for the Sealed Deck feature (issue #392, migration
-- 0211's own deck_type ENUM change plus GameService.php/index.php/frontend
-- wiring) -- no further schema change here, same pattern 0205/.../0210
-- already used for their own code-only ships.
UPDATE schema_version SET version = '1.28.67' WHERE id = 1;

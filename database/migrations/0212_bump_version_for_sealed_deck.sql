-- Bumps schema_version for the Sealed Deck feature (issue #392, migration
-- 0211's own deck_type ENUM change plus GameService.php/index.php/frontend
-- wiring) -- no further schema change here, same pattern 0205/.../0210
-- already used for their own code-only ships. A new full feature bumps the
-- MINOR version (1.28.x -> 1.29.0), not the patch -- unlike the small
-- fixes/tweaks 1.28.47-.../1.28.67 were each one of, per the maintainer's
-- own convention.
UPDATE schema_version SET version = '1.29.0' WHERE id = 1;

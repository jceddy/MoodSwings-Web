-- ============================================================================
-- schema_migrations BOOKKEEPING ONLY for migrations 0021 through 0026.
-- ============================================================================
--
-- This file contains NO schema changes at all -- not even the version
-- bumps migrations/0021_add_schema_version.sql through
-- migrations/0026_bump_version_for_play_grant_fixes.sql themselves apply.
-- It only records those 6 filenames as already-applied in the
-- schema_migrations table.
--
-- When you'd actually want this: `composer migrate` (php-app/bin/
-- migrate.php) is the only thing that ever writes to schema_migrations --
-- production's own documented workflow (see "Applying migrations" above)
-- is to paste each individual migration file into phpMyAdmin by hand,
-- which runs the DDL but never touches schema_migrations. If a database's
-- schema already has 0021-0026's actual changes applied that way (or its
-- schema_migrations history is otherwise incomplete for some other
-- reason) but the table itself is missing some or all of these 6 rows,
-- a later `composer migrate` run would try to re-run them from scratch and
-- fail partway through (e.g. `CREATE TABLE schema_version` erroring
-- because it already exists) instead of correctly skipping straight to
-- 0027 onward.
--
-- ONLY run this against a database where migrations 0021-0026's actual
-- schema changes are already present -- confirm first (e.g. `DESCRIBE
-- schema_version`, `SHOW TABLES LIKE 'game_team_decisions'`, `SHOW TABLES
-- LIKE 'game_initial_card_passes'`, and that `games.format` already
-- includes 'closed_team'). Running this against a database that's
-- actually missing any of 0021-0026's real changes will falsely mark them
-- applied, and `composer migrate` will never go back and apply them.
--
-- INSERT IGNORE (rather than the plain INSERT the other consolidated
-- scripts use) makes this safe to run even if schema_migrations already
-- has some, but not all, of these 6 rows -- e.g. a database with a mixed
-- history of some migrations tracked via `composer migrate` and others
-- pasted by hand.
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES
    ('0021_add_schema_version.sql'),
    ('0022_add_open_team_play_support.sql'),
    ('0023_add_closed_team_play_support.sql'),
    ('0024_bump_version_for_shock_targeting_fix.sql'),
    ('0025_bump_version_for_hope_grant_fix.sql'),
    ('0026_bump_version_for_play_grant_fixes.sql');

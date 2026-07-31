-- ============================================================================
-- schema_migrations BOOKKEEPING ONLY for migrations 0026 through 0059.
-- ============================================================================
--
-- This file contains NO schema changes at all -- not even the version
-- bumps migrations/0026_bump_version_for_play_grant_fixes.sql through
-- migrations/0059_bump_version_for_instability_sequential_fix.sql
-- themselves apply. It only records those 34 filenames as already-applied
-- in the schema_migrations table.
--
-- When you'd actually want this: `composer migrate` (php-app/bin/
-- migrate.php) is the only thing that ever writes to schema_migrations --
-- production's own documented workflow (see "Applying migrations" above)
-- is to paste each individual migration file into phpMyAdmin by hand,
-- which runs the DDL but never touches schema_migrations. If a database's
-- schema already has 0026-0059's actual changes applied that way (or its
-- schema_migrations history is otherwise incomplete for some other
-- reason) but the table itself is missing some or all of these 34 rows, a
-- later `composer migrate` run would try to re-run them from scratch and
-- fail partway through (e.g. `ALTER TABLE ... ADD COLUMN` erroring
-- because the column already exists) instead of correctly skipping
-- straight to 0060 onward.
--
-- ONLY run this against a database where migrations 0026-0059's actual
-- schema changes are already present -- confirm first (e.g. `SHOW TABLES
-- LIKE 'draft_matches'`, `SHOW TABLES LIKE 'draft_winston_state'`,
-- `SHOW TABLES LIKE 'draft_grid_state'`, `SHOW TABLES LIKE
-- 'user_decklists'`, `SHOW TABLES LIKE 'user_lifetime_stats'`, `SHOW
-- TABLES LIKE 'push_subscriptions'`, `SHOW TABLES LIKE
-- 'discord_accounts'`, `SHOW TABLES LIKE 'password_resets'`, `DESCRIBE
-- users` includes `share_presence`, and that `SELECT version FROM
-- schema_version` already reads `1.6.2`). Running this against a
-- database that's actually missing any of 0026-0059's real changes will
-- falsely mark them applied, and `composer migrate` will never go back
-- and apply them.
--
-- INSERT IGNORE (rather than a plain INSERT) makes this safe to run even
-- if schema_migrations already has some, but not all, of these 34 rows --
-- e.g. a database with a mixed history of some migrations tracked via
-- `composer migrate` and others pasted by hand (this range's own
-- start, 0026, deliberately overlaps the tail end of
-- 0021-0026_schema_migrations_only.sql above -- INSERT IGNORE makes
-- running both scripts, in either order, harmless).
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES
    ('0026_bump_version_for_play_grant_fixes.sql'),
    ('0027_add_quick_draft_support.sql'),
    ('0028_add_draft_format.sql'),
    ('0029_add_quick_draft_previous_deck.sql'),
    ('0030_fix_rationalization_optional.sql'),
    ('0031_add_jceddys_75_quick_draft_pool.sql'),
    ('0032_add_winston_draft_support.sql'),
    ('0033_add_game_resignation_support.sql'),
    ('0034_add_grid_draft_support.sql'),
    ('0035_add_winston_draft_last_take_tracking.sql'),
    ('0036_widen_winston_draft_last_action_tracking.sql'),
    ('0037_bump_version_for_wonder_duplicity_fix.sql'),
    ('0038_add_user_decklists.sql'),
    ('0039_add_card_sets_collector_number.sql'),
    ('0040_bump_version_for_deck_builder_polish.sql'),
    ('0041_add_draft_match_first_player_choice.sql'),
    ('0042_add_user_lifetime_stats.sql'),
    ('0043_add_game_spectate_codes.sql'),
    ('0044_bump_version_for_repentance_extra_values_fix.sql'),
    ('0045_bump_version_for_validation_unconditional_reaction_fix.sql'),
    ('0046_bump_version_for_game_replay.sql'),
    ('0047_clean_slate_games_for_v1.sql'),
    ('0048_add_push_notifications.sql'),
    ('0049_scope_notification_cooldown_and_queue_by_game.sql'),
    ('0050_add_discord_accounts.sql'),
    ('0051_add_notification_cooldown_toggle.sql'),
    ('0052_bump_version_for_popstate_fix.sql'),
    ('0053_add_user_presence.sql'),
    ('0054_add_game_notes.sql'),
    ('0055_fix_hesitation_optional.sql'),
    ('0056_bump_version_for_hate_self_target_fix.sql'),
    ('0057_add_password_resets.sql'),
    ('0058_bump_version_for_game_creation_and_duplicity_fixes.sql'),
    ('0059_bump_version_for_instability_sequential_fix.sql');

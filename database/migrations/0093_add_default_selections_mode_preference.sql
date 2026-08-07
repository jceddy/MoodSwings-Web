-- "Default selections mode" as a personal preference, surfaced in the new
-- Settings dialog's "Game defaults" section -- distinct from
-- games.default_selections_mode (issue #274, migration 0087), which is a
-- per-game setting chosen once at creation and fixed for that game's
-- whole lifetime. This is instead this user's own default for that
-- per-game checkbox's initial state in the New Game dialog, so a player
-- who always wants it on (or off) doesn't have to re-check it every
-- time -- still freely changeable per game before creating it. Defaults
-- to 0 (unchecked/disabled), same reasoning share_presence's own
-- migration (0053) and disable_cooldown's (0051) document for their own
-- default-off columns; placed after is_bot, the last column migration
-- 0090 added to this table.
ALTER TABLE users
    ADD COLUMN default_selections_mode_preference TINYINT(1) NOT NULL DEFAULT 0 AFTER is_bot;

UPDATE schema_version SET version = '1.12.0' WHERE id = 1;

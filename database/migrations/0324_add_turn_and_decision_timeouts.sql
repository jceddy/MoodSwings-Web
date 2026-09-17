-- Issue #85: "Turn and reaction timeouts (auto-pass, auto-decide on
-- pending choices)" -- an opt-in-at-creation setting so a slow or
-- disconnected player doesn't leave everyone else stuck waiting
-- forever. Two nullable columns on `games` rather than a separate
-- table: `timeout_minutes` NULL means timeouts are off for this game
-- (the default, and the only allowed value for deck_type
-- 'sealed_pool_of_the_day'/'weekly_sealed_pool' -- see
-- GameService::createGame()'s own validation); a non-NULL value is how
-- long (in minutes) a player may sit idle -- on either their own
-- ordinary turn or a pending_decision targeting them -- before
-- `timeout_action` fires on their behalf. `timeout_action` is always
-- set together with `timeout_minutes` (never independently NULL/non-NULL
-- from it).
--
-- 30-minute floor: reported live, the maintainer's own dev/production
-- environments can only run the sweep cron (bin/apply_game_timeouts.php)
-- every 15 minutes, so a shorter configured timeout could sit unnoticed
-- for up to one whole extra cron interval past when it nominally
-- elapsed -- 15 minutes of actual idle time plus up to 15 minutes of
-- cron latency is the shortest realistic timeout this mechanism can
-- honor without misleading whoever configured it.
ALTER TABLE games
    ADD COLUMN timeout_minutes SMALLINT UNSIGNED DEFAULT NULL AFTER diagnostic_mode,
    ADD COLUMN timeout_action ENUM('auto_play', 'skip', 'resign') DEFAULT NULL AFTER timeout_minutes;

UPDATE schema_version SET version = '1.41.0' WHERE id = 1;

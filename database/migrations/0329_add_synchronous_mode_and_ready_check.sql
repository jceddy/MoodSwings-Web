-- Reported live: a "synchronous" game mode for two players who are both
-- actually sitting down to play live, right now -- distinct from issue
-- #85's own opt-in ASYNC timeouts (a slow/disconnected player is
-- resolved on their behalf after minutes to days of idle time). This is
-- the first of several increments: this one lands just the mode flag
-- and the pre-game "ready check" (both seats must confirm they're
-- actually looking at the game before it deals) for 2-player Traditional/
-- Duel -- the 30-second action timer, timeout-extension banking, the
-- match-wide chess clock, and Draft/Sealed Deck's own ready-check +
-- 60-second pick timer follow in later migrations, exactly the way issue
-- #85 itself shipped its own team-decision coverage and full-game
-- time-limit mode as separate follow-up increments rather than all at
-- once. See php-app/README.md's "Synchronous mode" section.
--
-- `synchronous_mode` is mutually exclusive with `timeout_minutes`/
-- `total_time_limit_minutes` (validated in GameService::createGame()) --
-- a game picks one of "off"/"async timeouts"/"synchronous" at creation,
-- never a combination.
ALTER TABLE games
    ADD COLUMN synchronous_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER total_time_limit_minutes;

-- One seat's own "I'm actually here, deal me in" confirmation -- NULL
-- until that player clicks Ready, stamped once they do. A practice bot
-- seat is stamped ready immediately at seating time (see
-- GameService::createGame()'s own insertPlayer helper) since there's no
-- one to click anything on its behalf. The game deals/starts once every
-- seated player's own row is non-NULL -- see
-- GameService::allPlayersReady()/markReady().
ALTER TABLE game_players
    ADD COLUMN ready_at TIMESTAMP NULL DEFAULT NULL AFTER resigned_at;

UPDATE schema_version SET version = '1.43.0' WHERE id = 1;

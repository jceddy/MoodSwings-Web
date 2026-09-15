-- Issue #85 follow-up, reported live: two extensions to the turn/decision
-- timeout feature (migration 0324).
--
-- 1. Open/Closed Team Play's own turn-order/draw-recipient decisions
--    (game_team_decisions) are now covered by the existing
--    games.timeout_minutes/timeout_action sweep too -- previously the
--    one documented gap in that feature. No schema change needed for
--    this half; see GameService::applyTimeoutToTeamDecision().
--
-- 2. A second, independent opt-in: games.total_time_limit_minutes (NULL
--    = off, otherwise 60-4320 i.e. 1-72 hours) is a hard cap on any ONE
--    player's own cumulative "clock" time across the whole game --
--    tracked in the new game_players.active_seconds_used, credited
--    every time a player finishes an ordinary turn, pending decision,
--    or team-decision propose/confirm (see GameService::touchLastMoveAt()'s
--    own $creditGamePlayerId parameter). Once a player's own total
--    would reach the limit, they're automatically resigned -- always
--    resign for this mode; unlike the per-turn timeout above, there's no
--    "auto-play"/"skip" choice, since the whole point is a hard ceiling
--    on how much of the game a single player is allowed to consume.
--    Independent of (and checked ahead of) games.timeout_minutes/
--    timeout_action -- a game may have either, both, or neither opt-in.
ALTER TABLE games
    ADD COLUMN total_time_limit_minutes SMALLINT UNSIGNED DEFAULT NULL AFTER timeout_action;

ALTER TABLE game_players
    ADD COLUMN active_seconds_used INT UNSIGNED NOT NULL DEFAULT 0 AFTER resigned_at;

UPDATE schema_version SET version = '1.42.0' WHERE id = 1;

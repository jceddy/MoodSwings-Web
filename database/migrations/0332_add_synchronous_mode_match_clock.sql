-- Synchronous mode's own match-wide chess clock (increment 4 -- see
-- migration 0329's own docblock for the roadmap this follows, and
-- migrations 0330/0331 for the per-action/per-pick timers this
-- complements). "Each player has a total 30 minutes for a match (in
-- best of 3) or an individual game (in single game matches) -- if the
-- user goes over the 30 minute allotment, they automatically lose."
--
-- No new accumulator column on `games`/`game_players` -- this reuses
-- game_players.active_seconds_used, the exact column
-- total_time_limit_minutes' own full-game mode already accumulates via
-- GameService::touchLastMoveAt(). Safe because the two modes are
-- mutually exclusive (createGame()'s own validation): a synchronous
-- game's active_seconds_used is never touched by that other feature.
-- GameService::advanceGameMatch()/advanceDraftMatch() now carry that
-- value forward into game 2/3 of a synchronous best-of-three match
-- (deliberately still reset to 0 for a non-synchronous, total_time_limit_minutes
-- match, which is a per-GAME limit) -- see those methods' own updated
-- docblocks.
--
-- deck_building_started_at: stamped every time draft_matches.status
-- transitions to 'deck_building' (the initial post-draft trim, and
-- every later match-game sideboard reuse) -- "the time spent on deck
-- building/sideboarding counts against your 30 minutes total match
-- time," which this phase otherwise has no timer or per-player "whose
-- turn" of its own to measure against.
--
-- deck_building_time_credited_at: per-seat, reset to NULL whenever
-- deck_building_started_at refreshes for a new window -- tracks
-- whether THIS seat's own elapsed deck-building time this window has
-- already been credited onto their active_seconds_used (by
-- GameService::creditDeckBuildingTimeIfNeeded(), on their first
-- submitDraftDeck() call this window), so a resubmission before the
-- game actually starts doesn't double-charge them, and so
-- GameService::applySynchronousDeckBuildingAbandonment()'s own cron
-- backstop knows which seat(s) a fully-abandoned match is actually
-- still waiting on.
ALTER TABLE draft_matches
    ADD COLUMN deck_building_started_at TIMESTAMP NULL DEFAULT NULL AFTER pick_deadline_at;

ALTER TABLE draft_match_players
    ADD COLUMN deck_building_time_credited_at TIMESTAMP NULL DEFAULT NULL AFTER deck_card_ids;

UPDATE schema_version SET version = '1.46.0' WHERE id = 1;

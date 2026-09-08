-- Reported live: "add a user setting to pause at the end of turn - if
-- the user has this setting enabled, then a game should not advance to
-- that user's turn, until they click an 'advance turn' button - this is
-- to allow users to more clearly see what happened during a previous
-- turn before/after scoring effects happen - sometimes even with the
-- log text available it is difficult to figure out for many users."
--
-- users.pause_before_own_turn: a personal preference (Settings dialog's
-- "Game defaults" section, alongside auto_pass_on_empty_hand/
-- auto_apply_scoring_bonuses). Defaults to 0 (off) -- unlike those two,
-- this deliberately ADDS a click before every one of this player's own
-- turns, so it's an explicit opt-in rather than a pure convenience.
--
-- game_rounds.turn_pending_acknowledgment: set by
-- GameService::notifyItsYourTurn() -- the single existing "it just
-- became this player's turn" hook already used for the "your turn" push/
-- Discord notification, now also the moment this flag gets set, for
-- ANY turn handoff (an ordinary mid-round pass-the-turn, or a brand new
-- round starting after scoring) -- whenever the new current turn holder
-- has pause_before_own_turn on. GameService::playMood()/pass() both
-- refuse to act while it's set (assertTurnAcknowledged()); the new
-- POST /games/advance-turn route (GameService::acknowledgeTurnStart())
-- is the only way to clear it.
ALTER TABLE users
    ADD COLUMN pause_before_own_turn TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_apply_scoring_bonuses;

ALTER TABLE game_rounds
    ADD COLUMN turn_pending_acknowledgment TINYINT(1) NOT NULL DEFAULT 0 AFTER current_turn_game_player_id;

UPDATE schema_version SET version = '1.35.0' WHERE id = 1;

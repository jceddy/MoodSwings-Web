-- Synchronous mode's own live action timer (increment 2 -- see
-- migration 0329's own docblock for the roadmap this follows). Two new
-- columns on `games` track who is currently on the clock and when
-- their 30-second window expires; three new columns on `game_players`
-- track that player's own timeout-extension bank, how many turns in a
-- row they've acted within the window (earning more extensions), and
-- how many CONSECUTIVE times in a row their own window has expired
-- with no extension left to cover it (two in a row auto-resigns them --
-- see GameService::enforceSynchronousActionDeadline()'s own docblock).
--
-- action_deadline_at is a plain TIMESTAMP, not a duration -- it's
-- recomputed (NOW() + 30 seconds) after every real action in a
-- synchronous game, the same "forward-looking deadline, not a
-- backward-looking last-activity mark" reasoning that kept this off
-- games.last_move_at itself (see GameService::resetSynchronousActionDeadline()'s
-- own docblock): last_move_at already has other consumers (the lobby's
-- own sort order, active_seconds_used's own interval math) that assume
-- it always means exactly "the moment the chess clock last reset,"
-- which a hard per-action deadline would conflict with.
ALTER TABLE games
    ADD COLUMN action_deadline_at TIMESTAMP NULL DEFAULT NULL AFTER synchronous_mode,
    ADD COLUMN action_deadline_game_player_id INT UNSIGNED DEFAULT NULL AFTER action_deadline_at,
    ADD CONSTRAINT fk_games_action_deadline_player FOREIGN KEY (action_deadline_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL;

ALTER TABLE game_players
    ADD COLUMN timeout_extensions_banked TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER ready_at,
    ADD COLUMN clean_turn_streak TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER timeout_extensions_banked,
    ADD COLUMN consecutive_timed_out_actions TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER clean_turn_streak;

UPDATE schema_version SET version = '1.44.0' WHERE id = 1;

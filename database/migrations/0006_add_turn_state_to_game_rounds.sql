-- Adds live turn state to game_rounds: whose turn it currently is, and how
-- many more moods they may still play this turn (starts at 1, or 2 if
-- they hold Hurt Feelings; incremented by cards like Charity). This has
-- to be persisted between requests -- a player's turn can span multiple
-- plays granted by "you may play an additional mood" effects, and each
-- play is its own request/response round trip, so there's no in-memory
-- process alive between them to remember it in.
ALTER TABLE game_rounds
    ADD COLUMN current_turn_game_player_id INT UNSIGNED DEFAULT NULL AFTER first_game_player_id,
    ADD COLUMN plays_remaining TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER current_turn_game_player_id;

ALTER TABLE game_rounds
    ADD CONSTRAINT fk_game_rounds_current_turn FOREIGN KEY (current_turn_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL;

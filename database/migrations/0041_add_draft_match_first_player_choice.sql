-- Lets the loser of a best-of-three draft match's game N choose who goes
-- first in game N+1 (Quick Draft/Winston Draft/Grid Draft, issue #88/#89/
-- #188's shared match progression -- see GameService::advanceDraftMatch()).
-- Previously startGame() picked the first player of every game, including
-- games 2/3 of a match, with a uniform coin flip -- see its own docblock.
-- first_player_choice_user_id is nullable and purely optional: the choice
-- is never required to start the game (GameService::startGame() falls
-- back to game N's own winner going first again if the loser doesn't
-- exercise it -- see chooseFirstPlayerForNextMatchGame()'s own docblock).
-- Scoped to `games` (not draft_matches) since it's specific to one
-- particular game within the match, the same way match_game_number itself
-- already is.
ALTER TABLE games
    ADD COLUMN first_player_choice_user_id INT UNSIGNED DEFAULT NULL AFTER match_game_number,
    ADD CONSTRAINT fk_games_first_player_choice FOREIGN KEY (first_player_choice_user_id) REFERENCES users (id) ON DELETE SET NULL;

UPDATE schema_version SET version = '0.14.0' WHERE id = 1;

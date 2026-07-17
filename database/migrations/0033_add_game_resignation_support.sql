-- "Resign game" (lets a player give up instead of playing a game out).
--
-- resigned_at marks a seat as having resigned -- left NULL for every
-- player who hasn't. For 2-player games (duel/draft) and team-format
-- games (team/closed_team, always 2 opposing sides), a resignation
-- immediately completes the whole game in the remaining side's favor,
-- exactly like a normal round-based win -- no new round status is
-- needed for that case, since the round is simply abandoned mid-play.
-- The 'standard' format uniquely supports 3-4 players though, and for
-- that case resigning does NOT end the game: the resigning player is
-- marked out (their future turns skipped, and they're excluded from
-- ever winning a round or the game), while the rest of the table plays
-- on to a normal finish. Either way, the round a resignation happens
-- during needs to be taken out of 'in_progress' so GameService's own
-- currentRound() (which every play/pass already gates on) can't be used
-- to keep playing against a round whose game has already ended --
-- that's what the new 'abandoned' round status is for.
ALTER TABLE game_players
    ADD COLUMN resigned_at TIMESTAMP NULL DEFAULT NULL AFTER left_at;

ALTER TABLE game_rounds
    MODIFY COLUMN status ENUM('in_progress', 'scored', 'abandoned') NOT NULL DEFAULT 'in_progress';

UPDATE schema_version SET version = '0.8.0' WHERE id = 1;

-- puzzles.opponent_takes_turns: a puzzle whose opponent plays real turns. Work in
-- progress: the puzzle row itself is added to this migration once it is settled.
ALTER TABLE puzzles
    ADD COLUMN opponent_takes_turns TINYINT(1) NOT NULL DEFAULT 0 AFTER starting_skip_scoring_first_player;

-- 32nd puzzle, "Backfire" (a Passion puzzle: "Each player has two round wins, and
-- your opponent played first this round. Your opponent has Generosity, Charity and
-- Courage in play. You have Passion in play and hold Hate, Anger, Patience and Joy,
-- and you have one play. Win the game this round."), with one piece of puzzle
-- infrastructure: a solve is now decided with Passion/Enthusiasm bonuses counted.
--   * GameService::puzzleSolverOutscoresOpponent() (the check behind the 'win_game'
--     and 'outscore_opponent' goals) scored the board with no Enthusiasm/Passion
--     bonus at all -- those are scoring-time decisions, and a puzzle is solved
--     without that pause -- so a Passion in a puzzle was worth nothing. It now
--     counts them as taken the way a bot takes them (always, on the highest-value
--     target), which is the choice a solver would make.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning this
-- round wins the game; the opponent went first, so a tie goes to them.
--   Opponent: Generosity (green 6), Charity (white 1), Courage (white 1): 8.
--   Solver: Passion (red 0) in play, so you also score their best mood: Generosity,
--   6 -- 6 against 8. Hand: Hate, Anger, Patience, Joy; one play.
--   Hate (black 0): put any mood on the bottom of the deck. Anger (red 0): put any
--   number of moods with total value 5 or less into the discard pile. Patience (white
--   5, but 1 if you played it this round). Joy (green 3).
--
-- The one winning line (exhaustive search through the real GameService flow, every
-- Hate target and every Anger set included):
--   JOY: Passion still scores Generosity, 6 + 3 = 9 against 8. A win by one.
-- Why nothing else works:
--   Hate on Generosity looks like the play -- their biggest mood is gone -- but your
--   Passion was scoring it too. Passion now scores Charity (1) against their
--   remaining 2: 1-2. (Hate on a small mood leaves 6-7.)
--   Anger can only reach the small moods (Generosity is worth more than 5): Charity
--   and Courage gone leaves 6 against Generosity's 6, a tie, and a tie goes to the
--   opponent.
--   Patience reads 1 once it is played: 7-8.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'backfire',
    'Backfire',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Generosity, Charity and Courage in play. You have Passion in play and hold Hate, Anger, Patience and Joy, and you have one play. Win the game this round.',
    'Passion can only score what is still on the table.',
    'medium',
    '[66, 80, 21, 125]', '[97]', '[]', '[]',
    '[]', '[120, 3, 7]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, NULL, 1
);

UPDATE schema_version SET version = '1.62.30' WHERE id = 1;

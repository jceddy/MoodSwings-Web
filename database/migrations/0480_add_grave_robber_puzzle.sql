-- 31st puzzle, "Grave Robber" (a Melancholy / Recklessness / Validation puzzle: "Each
-- player has two round wins, and your opponent played first this round. Your
-- opponent has Melancholy, Joy, Faith and Worry in play. You have Validation and
-- Charity in play and hold Recklessness and Boredom, and you have one play. The
-- discard pile holds Chivalry and Discipline. Win the game this round."). No new
-- puzzle infrastructure: goal_type 'win_game'.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning this
-- round wins the game; the opponent went first, so a tie goes to them.
--   Opponent: Melancholy (black 3), Joy, Faith and Worry (3 each): 12.
--   Solver: Validation (white 1) and Charity (white 1): 2. Hand: Recklessness (red
--   0) and Boredom (red 4). Discard pile: Chivalry (white 3, but 5 if you did not go
--   first this round -- 5 for the solver) and Discipline (white 6, but 3 if there are
--   two or more black and/or red moods -- Melancholy is black and Recklessness red).
--   Melancholy (black 3): while in play, you may play moods from the discard pile as
--   though they were in your hand. Recklessness (red 0): after playing, you may take
--   one of your opponents' moods; if you do, you give it back after scoring.
--   Validation (white 1): after playing it, an additional mood; while in play, each
--   time you play another mood with a 0 or 1 printed in its corner, an additional
--   mood -- so Recklessness (0) buys the one extra play this puzzle depends on.
--
-- The one winning line (exhaustive search through the real GameService flow, every
-- Recklessness target, plays from the discard pile included):
--   Recklessness, taking MELANCHOLY: Validation grants an additional play, and
--   Melancholy is now yours, so that play can come from the discard pile. Play
--   CHIVALRY: 2 + 3 + 0 + 5 = 10 against 9. A win by one.
-- Why nothing else works: Discipline from the discard pile reads 3 (8-9), not 6.
-- The ties: Recklessness taking Melancholy and then Boredom from your hand (9-9), or
-- Recklessness taking Joy, Faith or Worry (3 for you, 3 off them) and then Boredom
-- (9-9) -- a tie goes to the opponent. Boredom alone, or Recklessness without taking
-- anything, leaves you far behind.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'grave-robber',
    'Grave Robber',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Melancholy, Joy, Faith and Worry in play. You have Validation and Charity in play and hold Recklessness and Boredom, and you have one play. The discard pile holds Chivalry and Discipline. Win the game this round.',
    'A card is not always worth its printed number by the time it is on the table.',
    'medium',
    '[100, 83]', '[26, 3]', '[]', '[4, 9]',
    '[]', '[69, 125, 12, 52]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, NULL, 1
);

UPDATE schema_version SET version = '1.62.29' WHERE id = 1;

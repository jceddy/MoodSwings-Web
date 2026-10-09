-- 18th puzzle, "Head Count" (a Euphoria puzzle: "Each player has two round
-- wins, and your opponent played first this round. Your opponent has Triumph
-- and three copies of Patience in play. You have Euphoria, Hope and Harmony
-- in play, there is an Intimidation in the discard pile, and you hold Panic
-- and Boredom. You have two plays. Win the game this round."). No new puzzle
-- infrastructure: goal_type 'win_game', Hope's extra play seeded through
-- extra_play_source_card_id, the discard pile through starting_discard_card_ids.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning
-- this round wins the game; the opponent went first, so a tie goes to them.
-- Opponent: Triumph (5 -- it is worth 5 for whoever went first this round)
-- and three Patience (5 each -- Patience drops to 1 if its owner played it
-- this round) = 20. Solver: Euphoria (0, +1 per mood in play, everyone's),
-- Hope (0) and Harmony (2) in play: 9 against 20 to start.
--
-- The one winning line (exhaustive search through the real GameService flow):
--   1. Panic, choosing BOTH players: the opponent's Triumph goes back to
--      their hand, and so does the solver's own Harmony.
--   2. Harmony again: its extra play can only be spent on a card from the
--      discard pile.
--   3. Intimidation from the discard pile, targeting the opponent: their only
--      card in hand -- the Triumph -- is put into the solver's hand, with an
--      extra play for that card alone.
--   4. Play the stolen Triumph. The solver did not go first, so it is worth
--      3 here, not 5.
--   Final: nine moods in play (six of the solver's, three Patience) make
--   Euphoria 9; Euphoria 9 + Hope 0 + Harmony 2 + Panic 1 + Intimidation 1 +
--   Triumph 3 = 16 against three Patience, 15 -- a win by one.
-- Stealing a Patience instead is the trap: it is worth only 1 once the
-- solver plays it, so 14 against 15 -- a loss.
-- The tying line: Panic bouncing one of the opponent's moods, then Boredom:
-- Euphoria 8 + Hope 0 + Harmony 2 + Panic 1 + Boredom 4 = 15, level with
-- the opponent's remaining 15 -- and a tie goes to the opponent.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'head-count',
    'Head Count',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Triumph and three copies of Patience in play. You have Euphoria, Hope and Harmony in play, there is an Intimidation in the discard pile, and you hold Panic and Boredom. You have two plays. Win the game this round.',
    'Some cards are worth a different amount in your hands than in your opponent''s.',
    'hard',
    '[48, 83]', '[117, 124, 123]', '[]', '[67]',
    '[]', '[104, 21, 21, 21]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, 124, 1
);

UPDATE schema_version SET version = '1.61.12' WHERE id = 1;

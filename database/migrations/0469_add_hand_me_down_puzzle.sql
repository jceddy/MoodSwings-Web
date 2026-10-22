-- 29th puzzle, "Hand-Me-Down" (a Thrill/Harmony/Suspicion puzzle: "Each player has
-- two round wins, and your opponent played first this round. Your opponent has
-- Generosity, Generosity and Generosity in play and holds Bashfulness. You have
-- Vulnerability, Complacency and Indifference in play and hold Thrill, Harmony
-- and Suspicion, and you have one play. Win the game this round."). No new puzzle
-- infrastructure: goal_type 'win_game'.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning this
-- round wins the game; the opponent went first, so a tie goes to them.
--   Opponent: Generosity (green 6) three times: 18. Their hand is one card,
--     Bashfulness (blue 6), so Suspicion can only make them discard that.
--   Solver: Vulnerability (green 1, but 7 once any card has gone to the discard
--     pile this round), Complacency 4 and Indifference 4: 9 now.
--   Thrill (red 1): return any of your other moods to your hand, and play that
--     many additional moods. Harmony (green 2): play an additional mood from the
--     discard pile. Suspicion (black 3): chosen players discard a card.
--
-- The one winning board (exhaustive search through the real GameService flow,
-- every Thrill return set, every Suspicion target set, plays from the discard
-- pile included):
--   1. Thrill, returning COMPLACENCY and INDIFFERENCE: two additional plays.
--   2. Suspicion and Harmony (either order), using them: the opponent has to
--      discard Bashfulness, which makes Vulnerability worth 7, and Harmony allows
--      one more play, from the discard pile.
--   3. Bashfulness from the discard pile.
--   Thrill 1 + Suspicion 3 + Harmony 2 + Bashfulness 6 + Vulnerability 7 = 19
--   against 18. A win by one.
--   (Returning Vulnerability as well and replaying it is the same board.)
-- The traps: Suspicion on its own is 7 + 4 + 4 + 3 = 18, a tie, and a tie goes to
-- the opponent. Vulnerability reads 1, so it looks like the mood to return; but
-- returning it (with a 4) leaves 4 + 1 + 3 + 2 + 6 = 16.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'hand-me-down',
    'Hand-Me-Down',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Generosity, Generosity and Generosity in play and holds Bashfulness. You have Vulnerability, Complacency and Indifference in play and hold Thrill, Harmony and Suspicion, and you have one play. Win the game this round.',
    'Thrill buys plays, but every mood you return costs you points.',
    'hard',
    '[103, 123, 78]', '[132, 5, 44]', '[]', '[]',
    '[30]', '[120, 120, 120]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, NULL, 1
);

UPDATE schema_version SET version = '1.62.18' WHERE id = 1;

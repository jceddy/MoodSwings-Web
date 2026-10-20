-- 27th puzzle, "Borrowed Time" (an Awe / Insecurity puzzle: "Each player has two
-- round wins, and your opponent played first this round. Your opponent has
-- Self-Loathing, Bashfulness, Apathy and Panic in play. You have Hope in play
-- and hold Insecurity, Awe, Patience and Glee. You have two plays. Win the
-- game."). goal_type 'win_game' as ever -- but this is the first puzzle that
-- can run past one turn: Awe's "there is no scoring this round" no longer ends
-- the attempt, it carries it into a second round (GameService::advancePuzzleTurn()
-- -> skipScoringAndAdvance()). Everything in play stays there, nothing is
-- drawn, the opponent (who never plays in a puzzle) keeps their board, and the
-- solver is seated for the new round's turn with a fresh turn's plays -- while
-- whoever Awe chose is that round's FIRST player, which is who a tie goes to.
-- Any other way for the solver's turn to end without a win is still a loss.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning
-- a round wins the game; the opponent went first this round, so a tie here
-- goes to them. Opponent: Self-Loathing (black 6) + Bashfulness (blue 6) +
-- Apathy (black 4) + Panic (blue 1) = 17, with nothing to switch off.
-- Solver: Hope (green 0) in play -- one extra play every turn, this turn
-- included, so two plays now and two next round.
--   Insecurity (blue 2): after playing it, you may play an additional mood
--     this turn; that mood returns to your hand after scoring.
--   Awe (green 4): no scoring this round, no one wins or loses it, and you
--     choose who goes first next round (after-scoring effects don't happen).
--   Patience (white 5): worth 1 if you played it THIS round.
--   Glee (red 0): worth 6 if you played it THIS round.
--
-- The one winning line (exhaustive search through the real GameService flow,
-- every play order, every Awe choice, passing included):
--   Round 1: Insecurity, then Awe as its additional mood (the return to hand
--     is an after-scoring effect, and there is no scoring, so Awe stays), then
--     Patience -- worth only 1 now, but it will have been played LAST round.
--     Choose YOURSELF to go first next round.
--   Round 2: Glee -- worth 6 because it is played this round.
--   Hope 0 + Insecurity 2 + Awe 4 + Patience 5 + Glee 6 = 17 against 17, and a
--   tie goes to the first player, which Awe made you. (Insecurity can equally
--   be played in the second round instead of the first -- the very same board.)
-- The tying lines: that same board with the OPPONENT chosen to go first is 17-17
--   and theirs. The tempting lines: Glee now (worth 6) scores only 9 against 17
--   this round, and anything that ends the turn without Awe loses; Patience
--   played next round is worth 1, and Glee played now is worth 0 next round.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'borrowed-time',
    'Borrowed Time',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Self-Loathing, Bashfulness, Apathy and Panic in play. You have Hope in play and hold Insecurity, Awe, Patience and Glee. You have two plays. Win the game.',
    'Some of your moods are worth more in the round after you play them -- and some are not.',
    'hard',
    '[45, 107, 21, 92]', '[124]', '[]', '[]',
    '[]', '[75, 30, 55, 48]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, 124, 1
);

UPDATE schema_version SET version = '1.62.14' WHERE id = 1;

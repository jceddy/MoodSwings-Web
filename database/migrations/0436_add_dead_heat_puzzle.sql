-- 14th puzzle, "Dead Heat" ("Your opponent played first this round, and
-- you and your opponent both have two round wins. Your opponent has no
-- cards in hand. You have Vanity in play and both Recklessness and Boredom
-- in hand. Your opponent has Hope, two copies of Betrayal, and Avoidance in
-- play. Win the game this turn."). No new puzzle infrastructure: goal_type
-- 'win_game' with a max_plays cap of 2.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so
-- winning this round wins the game; the opponent went first, so a tie goes
-- to them. Opponent: Hope (0), Betrayal (6) x2, Avoidance (3) = 15 points,
-- no hand. Solver: Vanity in play (0, +1 per own mood, or +3 per own mood
-- while the solver's hand is empty), Recklessness (0) and Boredom (4) in
-- hand, one play for the turn.
--
-- The one winning line (an exhaustive search of every legal play order and
-- every Recklessness target, through the real GameService flow):
--   1. Recklessness, taking the opponent's HOPE. Hope now works for the
--      solver, so the turn has a second play.
--   2. Boredom. Hand empty, four of the solver's moods in play (Vanity,
--      Recklessness, Hope, Boredom): Vanity is worth 12, plus Boredom's 4 =
--      16 against the opponent's 15.
--
-- The tempting wrong lines:
--   * Recklessness taking a Betrayal (a 6-point swing) or Avoidance: no
--     second play, so Boredom can't be played this turn -- scored as it
--     stands the solver only ties 9-9 against the Betrayal (or falls short)
--     and a tie goes to the opponent.
--   * Boredom first, then Recklessness: same one play, Recklessness is stuck
--     in hand.
--
-- Why max_plays matters: a puzzle seat never really "ends its turn" -- when
-- its plays run out the engine just hands it a fresh mini-turn
-- ('puzzle_turn_refreshed') instead of scoring the round. Without a cap the
-- search found 8 winning lines (the wrong Recklessness targets, or Boredom
-- first, with Boredom/Recklessness played on the refreshed turn), none of
-- which would work in a real game, where the round is scored the moment the
-- turn's plays are used up. Setting max_plays makes a refresh a failure, so
-- only the single-turn line counts. A cap of 2 or 3 gives the same single
-- solution; 2 is exactly this line.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'dead-heat',
    'Dead Heat',
    'Each player has two round wins, and your opponent played first, so ties go to them. You have Vanity in play and hold Recklessness and Boredom. Your opponent has Hope, two copies of Betrayal and Avoidance in play, and no cards in hand. Win the game this turn.',
    'Your turn ends, and the round is scored, the moment your plays run out. Work out what you would have on the board then -- and where a second play could come from -- before you take anything.',
    'medium',
    '[100, 83]', '[79]', '[]', '[]',
    '[]', '[124, 56, 56, 29]',
    2, 2,
    'win_game', '{}', 2, NULL, 1
);

UPDATE schema_version SET version = '1.60.4' WHERE id = 1;

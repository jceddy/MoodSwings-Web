-- 27th puzzle, "Borrowed Time" (an Awe / Recklessness puzzle: "Each player has two
-- round wins. Your opponent played first this round, and played Awe, choosing to
-- go first next round too. Your opponent has Awe, Patience, Glee, Boredom, Panic
-- and Charity in play (they played Patience and Glee this round). You hold
-- Recklessness, Glee and Patience, and you have one play a turn. Win the game.").
-- goal_type 'win_game' as ever, with new puzzle infrastructure so that a puzzle
-- can open in a round that is already skipping scoring and carry on into the next:
--   * puzzles.starting_skip_scoring_first_player ('solver' | 'opponent', NULL =
--     none): the round opens already marked to skip scoring -- as if the
--     opponent had just played Awe -- with that seat chosen to go first next
--     round, which is who a tie there goes to. See createPuzzleAttempt().
--   * An Awe-marked round no longer ends a win_game attempt when the solver's
--     turn does: GameService::advancePuzzleTurn() carries it into the next round
--     through skipScoringAndAdvance() (the solver is seated for that round's turn
--     -- the puzzle opponent never plays -- while the chosen seat is its first
--     player), and checkPuzzleGoal() never counts a win in a round that skips
--     scoring. Any other way for the turn to end without a win is still a loss.
--   * starting_effect_state may use 'playedInRound': 'current' and
--     'playedByPlayerId': 'solver' | 'opponent' (the live round's number and a
--     seat's id don't exist until the attempt does): how the opponent's Patience
--     and Glee start the puzzle already played by them THIS round.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning a
-- round wins the game. Awe makes THIS round unscored, so only the next round
-- counts -- and the opponent chose to go first in it, so a tie goes to them.
-- Moods that change value at the round boundary: Patience (white 5, but 1 if you
-- played it this round) and Glee (red 0, but 6 if you played it this round) --
-- and "you" is whoever holds the mood, so a stolen one was never played by its
-- thief. The opponent played both this round: Patience reads 1 and Glee 6 now,
-- and next round Patience is 5 and Glee 0.
-- Recklessness (red 0): after playing it, you may take one of your opponents'
-- moods; after scoring, give the mood you took back to them if you still have it.
-- There is no scoring: Awe says "after-scoring effects don't happen", so the
-- taken mood is simply yours from now on (and Recklessness's own "bottom of the
-- deck and draw" doesn't happen either).
-- Opponent next round: Awe 4 + Patience 5 + Glee 0 + Boredom 4 + Panic 1 +
-- Charity 1 = 15. Solver: Recklessness, Glee, Patience in hand; one play a turn.
--
-- The one winning line (exhaustive search through the real GameService flow,
-- every play order, every Recklessness target, passing included):
--   This round: Recklessness, taking the opponent's PATIENCE -- it reads 1 now,
--     but it is 5 next round, and the steal is permanent. (The turn ends.)
--   Next round: Glee -- worth 6 because it is played that round.
--   Recklessness 0 + Patience 5 + Glee 6 = 11 against Awe 4 + Glee 0 + Boredom 4 +
--   Panic 1 + Charity 1 = 10. A win by one.
-- The tying line: your own Patience now (5 next round) and Recklessness next
--   round, taking their Patience: 10 against 10, and a tie goes to the opponent.
-- The traps: Glee reads 6 now so it looks like the mood to take, but next round
--   it is worth 0 to whoever didn't just play it; taking Awe or Boredom (4 each)
--   leaves 10 against 11. Glee played now is wasted (0 next round).
ALTER TABLE puzzles
    ADD COLUMN starting_skip_scoring_first_player ENUM('solver', 'opponent') NULL AFTER starting_effect_state;

INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log, starting_effect_state,
    starting_skip_scoring_first_player,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'borrowed-time',
    'Borrowed Time',
    'Each player has two round wins. Your opponent played first this round, and played Awe, choosing to go first next round too. Your opponent has Awe, Patience, Glee, Boredom, Panic and Charity in play (they played Patience and Glee this round). You hold Recklessness, Glee and Patience, and you have one play a turn. Win the game.',
    'Awe means nothing is scored this round -- so what is each mood worth next round, and who will be holding it?',
    'hard',
    '[100, 92, 21]', '[]', '[]', '[]',
    '[]', '[107, 21, 92, 83, 48, 3]',
    2, 2, 0, NULL,
    '{"21":{"playedInRound":"current","playedByPlayerId":"opponent"},"92":{"playedInRound":"current","playedByPlayerId":"opponent"}}',
    'opponent',
    'win_game', '{}', NULL, NULL, 1
);

UPDATE schema_version SET version = '1.62.14' WHERE id = 1;

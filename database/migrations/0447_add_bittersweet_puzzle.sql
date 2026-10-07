-- 16th puzzle, "Bittersweet" ("Each player has two round wins, and your
-- opponent played first this round. Your opponent has Indifference, Laziness,
-- Joy and Charity in play. You have Misery and Complacency in play, and there
-- is a Rage in the discard pile. You hold Bliss, Boredom and Apathy, and you
-- have one play. Win the game this round."). No new puzzle infrastructure:
-- goal_type 'win_game' with the discard pile seeded through
-- starting_discard_card_ids.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning
-- this round wins the game; the opponent went first, so a tie goes to them.
-- Opponent: Indifference 4 + Laziness 4 + Joy 3 + Charity 1 = 12. Solver:
-- Misery (2, or 8 while two or more cards in the discard pile share a color)
-- and Complacency (4) in play; the discard pile holds one red card (Rage).
-- One play: Bliss, paid for by discarding Boredom (red 4) or Apathy (black 4).
-- Bliss scores each of your moods that shares a color with the discarded card
-- two extra times.
--   * Discard Apathy (the obvious Bliss play -- it matches Misery): red +
--     black in the discard pile share no color, so Misery stays 2, tripled to
--     6. 6 + Complacency 4 + Bliss 2 = 12 -- a tie with the opponent's 12, and
--     the opponent played first.
--   * Discard Boredom (the red card matching Rage in the discard pile): two
--     red cards there switch Misery on, 8, and nothing red is in play to
--     triple. 8 + 4 + 2 = 14 -- a win by two.
-- Playing Boredom or Apathy instead of Bliss leaves Misery at 2: 2 + 4 + 4 = 10.
-- Exhaustive search through the real GameService flow (every card, every
-- discard): only discarding Boredom wins.
--
-- Everything Bliss and Misery change moves a total by an even amount, so with
-- a single play the margin between the two discards is a multiple of two --
-- a tie against a win by two.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'bittersweet',
    'Bittersweet',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Indifference, Laziness, Joy and Charity in play. You have Misery and Complacency in play, and there is a Rage in the discard pile. You hold Bliss, Boredom and Apathy, and you have one play. Win the game this round.',
    'Bliss changes more than your moods when you pay for it -- look at everything that shifts once the card you choose lands in the discard pile.',
    'medium',
    '[108, 83, 55]', '[70, 5]', '[]', '[98]',
    '[]', '[44, 126, 125, 3]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, NULL, 1
);

UPDATE schema_version SET version = '1.61.10' WHERE id = 1;

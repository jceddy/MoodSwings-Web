-- 16th puzzle, "Bittersweet" ("Each player has two round wins, and your
-- opponent played first this round. Your opponent has Complacency, Laziness,
-- Boredom and Apathy in play. You have Hope, Misery and Avoidance in play and
-- a Rage in your discard pile. You hold Bliss, Stubbornness and
-- Indifference, with two plays. Win the game this round."). No new puzzle
-- infrastructure: goal_type 'win_game', the Hope extra play seeded through
-- extra_play_source_card_id, the discard pile through starting_discard_card_ids.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning
-- this round wins the game; the opponent went first, so a tie goes to them.
-- Opponent: Complacency + Laziness + Boredom + Apathy = 16. Solver: Hope (0),
-- Misery (2, or 8 while two or more cards in the discard pile share a color)
-- and Avoidance (3, blue) in play; the discard pile holds one red card (Rage).
--
-- Bliss scores each of your moods that shares a color with the card you
-- discard to play it two extra times. The only choice that matters is which
-- of Stubbornness (red 3) and Indifference (blue 4) to discard; the other is
-- played with the turn's second play:
--   * Discard Indifference (the obvious Bliss play -- it matches the blue
--     Avoidance): Avoidance scores 3x = 9, Misery stays 2 (red + blue in the
--     discard pile share no color), Bliss 2, then Stubbornness 3: 16 -- a tie
--     with the opponent's 16, and the opponent played first.
--   * Discard Stubbornness (the red card matching Rage in the discard pile):
--     two red cards there switch Misery on, 8; Avoidance stays 3, Bliss 2, then
--     Indifference 4: 17 -- a win by one.
-- Lines tried (exhaustive search through the real GameService flow, every
-- play order and discard): only discarding Stubbornness wins. Playing the
-- other card before Bliss reaches the same board either way, so the two play
-- orders count as one line.
--
-- Everything Bliss and Misery change moves a total by an even amount, so a
-- tie against a win by exactly one needs two hand cards of different values
-- played after the discard -- hence the second play.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'bittersweet',
    'Bittersweet',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Complacency, Laziness, Boredom and Apathy in play. You have Hope, Misery and Avoidance in play, and there is a Rage in the discard pile. You hold Bliss, Stubbornness and Indifference, and you have two plays. Win the game this round.',
    'Bliss changes more than your moods when you pay for it -- look at everything that shifts once the card you choose lands in the discard pile.',
    'medium',
    '[108, 102, 44]', '[124, 70, 29]', '[]', '[98]',
    '[]', '[5, 126, 83, 55]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, 124, 1
);

UPDATE schema_version SET version = '1.61.10' WHERE id = 1;

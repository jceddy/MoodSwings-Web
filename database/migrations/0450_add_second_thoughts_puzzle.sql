-- 19th puzzle, "Second Thoughts" (an Exhilaration puzzle: "Each player has two
-- round wins, and your opponent played first this round. Your opponent has
-- Frustration, Chaos, Pity, Enjoyment, Determination, Triumph and Boredom in
-- play. You have Joy, Melancholy, Frustration, Complacency and Chivalry in
-- play. You hold Exhilaration and Stubbornness, and you have two plays (one of
-- them from Joy). Win the game this round."). No new puzzle infrastructure:
-- goal_type 'win_game', Joy's banked extra play seeded through
-- extra_play_source_card_id.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning
-- this round wins the game; the opponent went first, so a tie goes to them.
-- Exhilaration scores all of its owner's moods an extra time, so the solver's
-- total is always twice the sum of their moods -- an even number.
--   Solver: Joy 3 + Melancholy 3 (the way to play a mood from the discard pile)
--   + Frustration 3 + Complacency 4 + Chivalry 5 (it is worth 5 to a player who
--   did not go first) = 18, doubled 36.
--   Frustration is 6, but 3 while there are two or more white and/or blue
--   moods -- Complacency and Chivalry are the two.
--   Opponent: Frustration 3 (same condition, same two moods) + Chaos 6 + Pity
--   6 + Enjoyment 6 + Determination 6 (each conditional card is on, thanks to
--   the opponent's own red moods) + Triumph 5 (worth 5 to whoever went first) +
--   Boredom 4 = 36.
--
-- The trap: Exhilaration, discarding any mood X, then playing X again from the
-- discard pile (Melancholy): the board is what it started as, plus
-- Exhilaration -- 36 against 36, and a tie goes to the opponent.
--
-- The one winning line (exhaustive search through the real GameService flow):
--   Exhilaration, discarding Complacency (the cheaper of the two white/blue
--   moods), and Stubbornness as the other play. Only Chivalry is left, so there
--   are fewer than two white and/or blue moods: BOTH Frustrations go to 6. The
--   solver's gain is doubled, the opponent's is not:
--   (Joy 3 + Melancholy 3 + Frustration 6 + Chivalry 5 + Stubbornness 3) x 2 =
--   40 against the opponent's 39 -- a win by one. (Everything on the solver's
--   side is doubled, so an odd margin needs a swing on the opponent's side; the
--   shared Frustration condition is that swing.)
-- Discarding Chivalry instead leaves 38 against 39 -- a loss by one.
-- Playing Stubbornness before Exhilaration reaches the same board, so the two
-- play orders count as one line.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'second-thoughts',
    'Second Thoughts',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Frustration, Chaos, Pity, Enjoyment, Determination, Triumph and Boredom in play. You have Joy, Melancholy, Frustration, Complacency and Chivalry in play. You hold Exhilaration and Stubbornness, and you have two plays (one of them from Joy). Win the game this round.',
    'Everything you do changes what some of the other moods in play are worth -- yours and your opponent''s.',
    'hard',
    '[89, 102]', '[125, 69, 90, 5, 4]', '[]', '[]',
    '[]', '[90, 85, 72, 115, 112, 104, 83]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, 125, 1
);

UPDATE schema_version SET version = '1.61.13' WHERE id = 1;

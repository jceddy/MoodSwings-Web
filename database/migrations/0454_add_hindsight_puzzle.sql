-- 21st puzzle, "Hindsight" (a Regret puzzle: "Each player has two round wins,
-- and your opponent played first this round. Your opponent has Wonder (set to
-- green), Joy, Suspicion and Apathy in play. You have Self-Loathing, Cheer and
-- Cynicism in play and hold Regret and Shame, and you have one play. Win the
-- game this round.").
--
-- New puzzle infrastructure: puzzles.starting_effect_state (JSON, NULL = none;
-- a JSON column can't take a DEFAULT on older MySQL), a map from catalog card id
-- to the per-mood state that card's in-play instance starts with -- here
-- {"133":{"colors":["green"]}}, the color the opponent's Wonder was played with
-- (exactly what WonderEffect::afterPlaying() stores). See
-- GameService::createPuzzleAttempt().
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning
-- this round wins the game; the opponent went first, so a tie goes to them.
-- Opponent: Joy (green 3) + Suspicion (black 3) + Apathy (black 4) + Wonder
-- (green): Wonder is worth 2 for each green mood in play and each green card
-- in the discard pile -- Wonder, Joy and the solver's Cheer, so 6 -- 16 in all.
-- Solver: Self-Loathing (black 6) + Cheer (green 3) + Cynicism (black 3) = 12,
-- holding Regret (blue 4) and Shame (white 3), and one play.
--
-- Regret: to play it, put two of your moods into your hand; after playing it,
-- put an opponent's mood into your hand. Three ways to pay, four moods to take
-- (solver / opponent totals):
--   * Return Cheer and Cynicism (keep Self-Loathing): 6 + Regret 4 = 10.
--       - Take WONDER (the obvious one -- the biggest mood on the board): the
--         opponent has Joy 3 + Suspicion 3 + Apathy 4 = 10. A tie, and a tie
--         goes to them.
--       - Take JOY (green, so it was pumping Wonder): Joy's own 3 goes, and so
--         does the 2 it gave Wonder. Cheer back in hand has already cost
--         Wonder another 2, so Wonder is just itself, 2: 3 + 4 + 2 = 9.
--         10 against 9 -- a win by one.
--       - Take Apathy: 3 + 3 + Wonder 4 (itself and Joy) = 10 -- another tie.
--       - Take Suspicion: 3 + 4 + 4 = 11 -- a loss.
--   * Return Self-Loathing and either other mood: the solver's board is only
--     7 (a 6 thrown away for a 3), which no steal makes up: 7 against 9-13.
-- Shame instead of Regret is 12 + 3 = 15 against 16.
-- Exhaustive search through the real GameService flow (every card in hand,
-- every pair of moods returned, every mood taken): only returning Cheer and
-- Cynicism and taking Joy wins.
--
-- Parity: the solver's side is fixed by the pair returned, and a mood that
-- pumps Wonder is worth its own value plus 2 to take -- so the margin between
-- the pump (Joy: 5) and Wonder itself (6) is exactly one.
ALTER TABLE puzzles
    ADD COLUMN starting_effect_state JSON NULL AFTER prelude_log;

INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log, starting_effect_state,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'hindsight',
    'Hindsight',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Wonder (set to green), Joy, Suspicion and Apathy in play. You have Self-Loathing, Cheer and Cynicism in play and hold Regret and Shame, and you have one play. Win the game this round.',
    'The biggest mood on the board is not always the one worth taking -- and look at what the two moods you give up to pay for Regret were doing.',
    'medium',
    '[50, 25]', '[75, 110, 62]', '[]', '[]',
    '[]', '[133, 125, 78, 55]',
    2, 2, 0, NULL, '{"133":{"colors":["green"]}}',
    'win_game', '{}', NULL, NULL, 1
);

UPDATE schema_version SET version = '1.62.3' WHERE id = 1;

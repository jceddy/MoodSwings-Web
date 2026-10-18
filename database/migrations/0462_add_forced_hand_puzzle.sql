-- 25th puzzle, "Forced Hand" (a Compulsion puzzle: "Each player has two round
-- wins, and your opponent played first this round. Your opponent has Loyalty
-- and Self-Loathing in play and holds one card. You have Joy in play and hold
-- Compulsion, Ambivalence and Pity, and you have two plays. Win the game this
-- round."). No new puzzle infrastructure: goal_type 'win_game', with Joy
-- seeded in play and as the source of the second play (extra_play_source_card_id).
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning
-- this round wins the game; the opponent went first, so a tie goes to them.
-- Compulsion (red 3): after playing it, the chosen player picks a card from
-- their hand and gives it to you. The opponent's hand is a single card, so
-- there is no choice for them to make: you get BOREDOM.
--   Opponent: Loyalty (white 3, but 6 while two or more green and/or blue moods
--     are in play) and Self-Loathing (black 6). Joy is the one green mood, so
--     Loyalty is one mood short of waking up: 3 + 6 = 9.
--   Solver: Joy (green 3). Hand: Compulsion (red 3), Ambivalence (blue 6, but 3
--     while two or more red and/or green moods are in play), Pity (black 3, but
--     6 while two or more blue and/or red moods are in play).
--
-- The one winning line (exhaustive search through the real GameService flow,
-- every legal play order and every Compulsion outcome): COMPULSION, then play
-- BOREDOM (red 4): Joy 3 + Compulsion 3 + Boredom 4 = 10 against Loyalty 3 +
-- Self-Loathing 6 = 9. A win by one.
-- The tempting line: Ambivalence + Pity. Both are off (3 + 6 + 3 = 12 against
-- the 9 it looks like you need to beat) -- but Ambivalence is blue, which with
-- Joy's green wakes Loyalty up: 12 against 12, and a tie goes to the opponent.
-- Compulsion + Pity also ties (9 against 9). Compulsion + Ambivalence loses:
-- Compulsion's red and Joy's green drop Ambivalence to 3, and its blue still
-- turns Loyalty on.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'forced-hand',
    'Forced Hand',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Loyalty and Self-Loathing in play and holds one card. You have Joy in play and hold Compulsion, Ambivalence and Pity, and you have two plays. Win the game this round.',
    'Total up what your other two cards look like they are worth -- then check whether either of them hands your opponent points too.',
    'medium',
    '[86, 27, 72]', '[125]', '[]', '[]',
    '[83]', '[18, 75]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, 125, 1
);

UPDATE schema_version SET version = '1.62.11' WHERE id = 1;

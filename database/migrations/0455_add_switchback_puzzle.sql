-- 22nd puzzle, "Switchback" (a Thrill puzzle: "Each player has two round wins,
-- and your opponent played first this round. Your opponent has Excitement,
-- Loyalty and Indifference in play. You have Disgust and Laziness in play and
-- hold Thrill, Complacency, Apathy and Pity, and you have one play. Win the
-- game this round."). No new puzzle infrastructure: goal_type 'win_game'.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning
-- this round wins the game; the opponent went first, so a tie goes to them.
-- Every mood here is worth a flat number or changes value with the colors
-- (of ALL players' moods) around it:
--   Excitement (red 3)  -> 6 while two or more black and/or green moods are in play
--   Loyalty (white 3)   -> 6 while two or more green and/or blue moods are in play
--   Disgust (black 6)   -> 3 while two or more green and/or white moods are in play
--   Pity (black 3)      -> 6 while two or more blue and/or red moods are in play
--   Indifference (blue 4), Laziness (green 4), Complacency (white 4),
--   Apathy (black 4), Thrill (red 1): flat.
-- At the start Disgust and Laziness are black/green, so Excitement is 6, and
-- Laziness is green with Indifference blue, so Loyalty is 6: the opponent has
-- 6 + 6 + 4 = 16 against the solver's Disgust 3 (Laziness green + Loyalty white
-- switch it down) + Laziness 4 = 7.
--
-- Thrill: after playing it, put any number of your other moods into your hand;
-- you may play that many additional moods. One play, so Thrill is the play.
-- The one winning line (exhaustive search through the real GameService flow,
-- 9 states, every Thrill return set and every follow-up play):
--   Thrill returning DISGUST and LAZINESS, then Complacency and Pity (either
--   order). Nothing black or green is left in play, so Excitement drops to 3
--   and Loyalty (only Indifference is blue/green) to 3: the opponent has
--   3 + 3 + 4 = 10. Thrill is red and Indifference blue, so Pity is 6:
--   Thrill 1 + Complacency 4 + Pity 6 = 11. A win by one.
-- The ties (a tie goes to the opponent):
--   * Thrill returning only Laziness, then Pity: Disgust stays and is black,
--     and with Pity it turns Excitement back on (6): Disgust 6 + Thrill 1 +
--     Pity 6 = 13 against 6 + 3 + 4 = 13.
--   * Thrill returning both, then replaying Disgust and Pity: the same board
--     as above, 13-13.
-- Complacency and Apathy instead of Pity is 9 against 10 (Pity is the only
-- mood Thrill itself switches on), and a single play from hand never gets
-- close.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'switchback',
    'Switchback',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Excitement, Loyalty and Indifference in play. You have Disgust and Laziness in play and hold Thrill, Complacency, Apathy and Pity, and you have one play. Win the game this round.',
    'Several of these moods change value with the colors around them -- the moods you take back into your hand are moods you can choose not to play again.',
    'medium',
    '[103, 5, 55, 72]', '[63, 126]', '[]', '[]',
    '[]', '[88, 18, 44]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, NULL, 1
);

UPDATE schema_version SET version = '1.62.4' WHERE id = 1;

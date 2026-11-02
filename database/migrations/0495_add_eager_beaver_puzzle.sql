-- 35th puzzle, "Eager Beaver" (an Eagerness/Rage puzzle: "Each player has two round
-- wins, and your opponent played first this round. Your opponent has Tranquility and
-- Bashfulness in play. You hold Eagerness, Indifference, Rage and Enjoyment, and you have
-- two plays. Win the game this round."). No new puzzle infrastructure: goal_type
-- 'win_game', with Joy in the discard pile as the banked play
-- (extra_play_source_card_id; a non-Hope source is a plain unconditional extra play).
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning this
-- round wins the game; the opponent went first, so a tie goes to them.
--   Opponent: Tranquility (green 3 -- it reads 6 only with an odd number of moods) and
--   Bashfulness (blue 6): 9.
--   Solver: nothing in play; hand: Eagerness (green 2), Indifference (blue 4), Rage
--   (red 2), Enjoyment (green 3 -- it reads 6 only with two red/white moods). Two plays:
--   the normal one and Joy's banked one. Eagerness grants a THIRD play, but only for a
--   card sharing a color with one of your moods -- checked when you play it.
--
-- The one winning line (exhaustive search through the engine, every choice and every
-- order, passing included -- 42 lines, 1 win):
--   RAGE (discarding every other mood worth 3 or less: Tranquility goes), then EAGERNESS,
--   then ENJOYMENT with Eagerness's play: 2 + 2 + 3 = 7 against Bashfulness's 6.
-- Why nothing else works:
--   Eagerness before Rage: Rage then discards your own Eagerness (a 2), and Enjoyment
--   (green) no longer shares a color with anything you have -- no play: 2-6.
--   Indifference looks like the best third play (a 4), but it is blue and the only color you
--   have is Eagerness's green: Rage, Eagerness, Indifference is not a legal play.
--   Indifference first spends a play on a card that does nothing about the opponent's
--   Tranquility: Indifference, Eagerness, Enjoyment is 9-9, a tie -- which goes to them.
--   Rage then Indifference alone: 6-6, a tie as well.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'eager-beaver',
    'Eager Beaver',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Tranquility and Bashfulness in play. You hold Eagerness, Indifference, Rage and Enjoyment, and you have two plays. Win the game this round.',
    'Eagerness only extends your turn to a color you have in play when you reach that play -- so mind what is still in play by then.',
    'medium',
    '[114,44,98,115]', '[]', '[1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,31,32,33,34,35,36,37,38,39,40,41,42,43,45,46,47]', '[125]',
    '[]', '[131, 30]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, 125, 1
);

UPDATE schema_version SET version = '1.63.13' WHERE id = 1;

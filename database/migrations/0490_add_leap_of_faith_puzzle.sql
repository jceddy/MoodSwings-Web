-- 34th puzzle, "Leap of Faith" (a Conviction/Hope puzzle: "Each player has two round
-- wins, and your opponent played first this round. Your opponent has Joy, Charity
-- and Courage in play. You have Hope in play and hold Conviction. You have two
-- plays. Win the game this round."). No new puzzle infrastructure: goal_type
-- 'win_game', with Hope seeded in play and as the source of the second play
-- (extra_play_source_card_id; a Hope source is the real Hope grant, which is lost
-- if Hope leaves play before that play is used -- see createPuzzleAttempt()).
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning this
-- round wins the game; the opponent went first, so a tie goes to them.
--   Opponent: Joy (green 3), Charity (white 1), Courage (white 1): 5. All three
--   are inert while in play.
--   Solver: Hope (green 0) in play; hand: Conviction (white 2). Two plays: the
--   normal one and Hope's. On top of the deck: Boredom (red 4, no ability), under
--   a full 45-card filler list (Hope left out of it, so extra_play_source_card_id
--   can only ever find the Hope in play). Conviction: "choose a mood. Its player
--   puts it on the bottom of the deck and draws a card" -- the mood's OWNER draws.
--
-- The one winning line (exhaustive search through the real GameService flow, every
-- Conviction target and every choice of which play to spend, passing included):
--   CONVICTION on your own HOPE, spending HOPE'S play on it, then BOREDOM (drawn)
--   with your normal play: Conviction 2 + Boredom 4 = 6 against 5. A win by one.
-- Why nothing else works:
--   Conviction on the opponent's biggest mood (Joy) looks like the play, but you have
--   nothing else to play, and it is the OPPONENT who draws: 2 against 2, a tie, and
--   a tie goes to them. (Charity or Courage: 2-4.)
--   Hope goes to the bottom of the deck either way, but if you spent your NORMAL play
--   on Conviction, Hope's play is lost with it -- you draw Boredom and cannot play
--   it: 2-5. (Spending Hope's play on Conviction is a choice, not the default.)
--   Conviction on itself draws Boredom but throws away its own 2: 4-5.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'leap-of-faith',
    'Leap of Faith',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Joy, Charity and Courage in play. You have Hope in play and hold Conviction. You have two plays. Win the game this round.',
    'Whoever owns the mood Conviction hits is the one who draws -- and it matters which of your two plays you spend on it.',
    'medium',
    '[6]', '[124]', '[83,113,93,116,22,101,14,38,10,88,4,45,12,28,48,81,122,64,36,9,56,92,112,104,98,121,132,90,26,21,42,55,58,109,18,115,91,20,66,77,126,131,37,65,119]', '[]',
    '[]', '[125, 3, 7]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, 124, 1
);

UPDATE schema_version SET version = '1.63.8' WHERE id = 1;

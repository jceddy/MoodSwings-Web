-- 28th puzzle, "Return to Sender" (a Denial puzzle: "Each player has two round
-- wins, and your opponent played first this round. Your opponent has Generosity,
-- Generosity, Thrill and Contempt in play. You have Validation and Recklessness
-- in play and hold Denial, and you have one play. Win the game this round.").
-- No new puzzle infrastructure: goal_type 'win_game'.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning
-- this round wins the game; the opponent went first, so a tie goes to them.
-- Denial (blue 1): after playing it, you may choose two other moods; if they
-- share a color or have the same value, put them into their players' hands.
-- Validation (white 1): each time you play another mood with a 0 or 1 in its top
-- right corner, you may play an additional mood -- so Denial (1) and Recklessness
-- (0) each pay for themselves. Recklessness (red 0): after playing it, you may
-- take one of your opponents' moods.
--   Opponent: Generosity (green 6) twice, Thrill (red 1) and Contempt (black 1):
--     6 + 6 + 1 + 1 = 14. Solver: Validation 1 + Recklessness 0, and Denial 1 once
--     played: 2.
--
-- The one winning line (exhaustive search through the real GameService flow,
-- every legal Denial pair and every Recklessness target):
--   1. Denial, bouncing THRILL and your own RECKLESSNESS -- both red, so legal --
--      and a play is granted by Validation. Their Thrill goes back to their
--      hand, 14 - 1 = 13 against your 1 + 1 = 2.
--   2. Recklessness again (0, so Validation grants another play), taking a
--      GENEROSITY: 2 + 6 = 8 against 6 + 1 = 7. A win by one.
--   (The two Generosities are identical, so which one you take is the same board.)
-- The trap: Denial bouncing the two Generosities (both green) leaves the opponent
-- with 1 + 1 = 2 against your 2: a tie, and a tie goes to the opponent. Every
-- other pair either isn't legal or leaves too much on their side.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'return-to-sender',
    'Return to Sender',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Generosity, Generosity, Thrill and Contempt in play. You have Validation and Recklessness in play and hold Denial, and you have one play. Win the game this round.',
    'Denial returns two moods to their players'' hands -- and you can play anything you pick up again.',
    'medium',
    '[34]', '[26, 100]', '[]', '[]',
    '[]', '[120, 120, 103, 59]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, NULL, 1
);

UPDATE schema_version SET version = '1.62.15' WHERE id = 1;

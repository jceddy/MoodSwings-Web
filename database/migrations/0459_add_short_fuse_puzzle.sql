-- 24th puzzle, "Short Fuse" (an Anger puzzle: "Each player has two round wins,
-- and your opponent played first this round. Your opponent has Obsession,
-- Wrath, Panic and Self-Loathing in play. You have Enjoyment, Bitterness and
-- Laziness in play and hold Anger, and you have one play. Win the game this
-- round."). No new puzzle infrastructure: goal_type 'win_game'.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning
-- this round wins the game; the opponent went first, so a tie goes to them.
-- Anger (red 0): after playing it, put any number of moods -- anyone's, its own
-- included -- with total value 5 or less into the discard pile. Values count as
-- they stand when you choose, so a mood already worth 6 can't be picked.
--   Opponent: Obsession (blue 3, but 6 while two or more white and/or black
--     moods are in play), Wrath (red 0), Panic (blue 1), Self-Loathing (black 6).
--     Obsession is ON: your Bitterness and their Self-Loathing are both black.
--     6 + 0 + 1 + 6 = 13.
--   Solver: Enjoyment (green 3, but 6 while two or more red and/or white moods
--     are in play), Bitterness (black 0), Laziness (green 4), and Anger once
--     played (red 0). Enjoyment is ON: Anger and the opponent's WRATH are the
--     two red moods. 6 + 0 + 4 + 0 = 10.
--   Playing Anger and discarding nothing: 10 against 13.
--
-- The one winning line (exhaustive search through the real GameService flow,
-- every subset of the nine moods in play): discard BITTERNESS and PANIC
-- (total value 0 + 1). Without Bitterness only one black mood is left, so
-- Obsession drops to 3, and Panic's 1 goes too: 10 against 3 + 0 + 6 = 9. A win
-- by one.
-- The tying line: discard Bitterness alone -- Obsession drops but Panic stays:
-- 10 against 10, and a tie goes to the opponent.
-- The traps: the opponent's Wrath is worth 0 and looks free to remove, but it is
-- one of the two red moods keeping your Enjoyment at 6 (Anger itself is the
-- other, so discarding Anger fails the same way). Every set containing either
-- loses; discarding Panic without Bitterness, or nothing, loses too.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'short-fuse',
    'Short Fuse',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Obsession, Wrath, Panic and Self-Loathing in play. You have Enjoyment, Bitterness and Laziness in play and hold Anger, and you have one play. Win the game this round.',
    'Anger can discard any number of moods as long as their values add up to 5 or less -- but look at which colors are holding up whose values.',
    'medium',
    '[80]', '[115, 57, 126]', '[]', '[]',
    '[]', '[47, 105, 48, 75]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, NULL, 1
);

UPDATE schema_version SET version = '1.62.8' WHERE id = 1;

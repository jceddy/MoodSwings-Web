-- 23rd puzzle, "First Things First" (an easy Validation sequencing puzzle:
-- "Each player has two round wins, and your opponent played first this round.
-- Your opponent has Obsession, Indifference and Laziness in play. You hold
-- Validation, Glee, Vulnerability, Sadness, Boredom and Apathy, and you have
-- one play. Win the game this round."). No new puzzle infrastructure:
-- goal_type 'win_game'.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning
-- this round wins the game; the opponent went first, so a tie goes to them.
-- Opponent: Obsession (blue 3, but 6 while two or more white and/or black
-- moods are in play), Indifference (blue 4), Laziness (green 4) = 3 + 4 + 4 =
-- 11 with Obsession off. Solver: nothing in play, one play.
--
-- Validation: after playing it you may play an additional mood; while it is in
-- play, each time you play another mood with a 0 or 1 in its top right corner
-- you may play an additional mood. So once Validation is down, every 0/1 mood
-- is free (it pays for itself) and the first mood worth more than 1 is the last
-- play you get. Glee (red 0) is worth 6 while you played it this round,
-- Vulnerability (green 1) and Sadness (black 0) are worth 1 and 0 here.
--
-- The one winning line (exhaustive search through the real GameService flow, 9
-- states): Validation, then Glee and Vulnerability (either order, both free),
-- then Boredom. Validation 1 + Glee 6 + Vulnerability 1 + Boredom 4 = 12
-- against 11 -- a win by one.
-- The tying line: Validation, Glee, Boredom without Vulnerability (a free play
-- left on the table): 11 against 11, and a tie goes to the opponent.
-- The trap: Sadness is free too, but it is black, and Validation is white --
-- two white/black moods switch Obsession on (6): 14 against 12. Apathy as the
-- big mood is black for the same reason. Playing Boredom (or any 2+ mood)
-- before the free ones leaves no play for them.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'first-things-first',
    'First Things First',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Obsession, Indifference and Laziness in play. You hold Validation, Glee, Vulnerability, Sadness, Boredom and Apathy, and you have one play. Win the game this round.',
    'Some of these cost you nothing to play -- if the order is right. And watch what the colors on the board do to your opponent''s moods.',
    'easy',
    '[26, 92, 132, 74, 83, 55]', '[]', '[]', '[]',
    '[]', '[47, 44, 126]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, NULL, 1
);

UPDATE schema_version SET version = '1.62.7' WHERE id = 1;

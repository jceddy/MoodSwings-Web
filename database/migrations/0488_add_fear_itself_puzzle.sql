-- 33rd puzzle, "Fear Itself" (a Fear puzzle: "Each player has two round wins, and
-- your opponent played first this round. Your opponent has Complacency, Indifference
-- and Apathy in play. You have Boredom and Laziness in play and hold Fear,
-- Superiority and Joy, and you have one play. Win the game this round."), with no
-- puzzle infrastructure. It only asks that you know what Fear does: "you may put
-- another one of your moods into your hand. You may play an additional mood this
-- turn." The extra play is not tied to returning a mood (there is no "if you do"),
-- so Fear is free -- it refunds the play it costs -- and you can play it without
-- returning anything.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning this
-- round wins the game; the opponent went first, so a tie goes to them.
--   Opponent: Complacency (white 4), Indifference (blue 4), Apathy (black 4): three
--   moods, 12. All three are the vanilla commons -- no abilities.
--   Solver: Boredom (red 4) and Laziness (green 4) in play: two moods, 8. Hand: Fear
--   (blue 0), Superiority (black 3, but 7 if you have more moods than each other
--   player) and Joy (green 3; its extra play is for next turn); one play.
--
-- The one winning line (exhaustive search through the real GameService flow, every
-- Fear return included):
--   FEAR, returning nothing, then SUPERIORITY: four moods against their three, so
--   Superiority reads 7 -- 4 + 4 + 0 + 7 = 15 against 12.
-- Why nothing else works:
--   Superiority alone makes three moods against their three -- "more" is not "as
--   many": it reads 3, 11 against 12. Joy alone is 11-12 as well, and Fear and then
--   Joy is no better.
--   Returning a mood with Fear (taking it for the extra play) costs you a body:
--   Fear and Superiority is then only three moods against three, 7-12; replaying the
--   mood you returned is back to 8-12.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'fear-itself',
    'Fear Itself',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Complacency, Indifference and Apathy in play. You have Boredom and Laziness in play and hold Fear, Superiority and Joy, and you have one play. Win the game this round.',
    'Read exactly what Fear asks of you.',
    'easy',
    '[38, 77, 125]', '[83, 126]', '[]', '[]',
    '[]', '[5, 44, 55]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, NULL, 1
);

UPDATE schema_version SET version = '1.63.6' WHERE id = 1;

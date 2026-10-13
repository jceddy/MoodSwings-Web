-- 20th puzzle, "Hope Springs Eternal" (a Hope puzzle: "Each player has two
-- round wins, and your opponent played first this round. Your opponent has
-- Apathy, Patience, Vulnerability, Bashfulness and Chaos in play. You have
-- Hope in play and hold Scorn, Fear, Stubbornness and Indifference. You have
-- two plays. Win the game this round."). No new puzzle infrastructure beyond
-- one fidelity fix: a seeded extra play whose source is Hope is now Hope's real
-- turn-start grant ('requiresSourceInPlay'), lost if Hope leaves play before
-- the play is used, instead of a banked play that outlives its source.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning
-- this round wins the game; the opponent went first, so a tie goes to them.
-- Opponent: Apathy (black 4) + Patience (white 5 -- 1 only if its owner played
-- it this round) + Vulnerability (green 1;
-- it is 7 only if a card was put into the discard pile this round, and nothing
-- here is) + Bashfulness (blue 6) + Chaos (red 6) = 22. Solver: Hope (0) in
-- play, two plays (the turn's own plus Hope's).
--
-- Scorn: after playing it, suppress any mood until the end of the round; while
-- it is in play, each time you play another mood you may suppress a mood
-- sharing a color with it. So every mood you play after Scorn is another chance
-- to suppress an opposing mood -- and Fear (blue) and Hope (green) are plays
-- that cost almost nothing.
--
-- The one winning line (exhaustive search through the real GameService flow):
--   1. Scorn, suppressing the Patience (the largest mood nothing else can reach).
--   2. Fear: suppress Bashfulness (blue, like Fear) and put HOPE into your hand
--      -- Hope's own play is already spent, so nothing is lost -- and Fear
--      grants a play.
--   3. Hope again: suppress Vulnerability (green, like Hope). Hope enters play
--      and grants a fresh play of its own.
--   4. Stubbornness: suppress Chaos (red, like Stubbornness).
--   Four moods suppressed -- only the Apathy (4) is left to the opponent -- and
--   Scorn 2 + Hope 0 + Fear 0 + Stubbornness 3 = 5 against 4: a win by one.
-- The tying line: skip the Hope bounce -- Scorn, Fear, Stubbornness -- and the
--   green Vulnerability (1) stays: 5 against 5, and a tie goes to the opponent.
-- Indifference (4) looks better than Stubbornness (3) but is blue, like Fear:
-- nothing new to suppress, so Chaos stays: a loss.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'hope-springs-eternal',
    'Hope Springs Eternal',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Apathy, Patience, Vulnerability, Bashfulness and Chaos in play. You have Hope in play and hold Scorn, Fear, Stubbornness and Indifference. You have two plays. Win the game this round.',
    'Count every mood you can play this turn -- each one matters to Scorn, whatever it is worth.',
    'hard',
    '[24, 38, 102, 44]', '[124]', '[]', '[]',
    '[]', '[55, 21, 132, 30, 85]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, 124, 1
);

UPDATE schema_version SET version = '1.62.2' WHERE id = 1;

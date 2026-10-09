-- 17th puzzle, "Deja Vu" (a Duplicity puzzle: "Each player has two round
-- wins, and your opponent played first this round. Your opponent has
-- Stubbornness, Obsession, Bashfulness and Neurosis in play. You have Hope
-- and Glee in play. You hold Duplicity, Shock, Nostalgia and Laziness, and
-- you have two plays. Win the game this round."). No new puzzle
-- infrastructure: goal_type 'win_game', Hope's extra play seeded through
-- extra_play_source_card_id.
--
-- Setup: both sides have two round wins (games.wins_needed is 3), so winning
-- this round wins the game; the opponent went first, so a tie goes to them.
-- Opponent: Stubbornness (3) + Obsession (3) + Bashfulness (6) + Neurosis (5)
-- = 17; only the two 3s are within Shock's reach. Solver: Hope (0) and Glee
-- (0 -- it is worth 6 only if played this round) in play.
--
-- Plays: the two from Hope, plus Duplicity's one, plus Nostalgia's TWO once
-- Duplicity doubles it: Duplicity, Shock, Nostalgia, and two more cards.
--
-- The one winning line (exhaustive search through the real GameService flow):
--   1. Duplicity.
--   2. Shock, doubled: across its two activations put BOTH of the opponent's
--      3s -- and your own Glee -- into the discard pile. The opponent is down
--      to 11; Glee, now in the discard pile, is the one that matters.
--   3. Nostalgia, doubled: take Glee back into your hand (and any other card).
--   4. Play Glee -- worth 6, since it is played this round -- and Laziness,
--      the card ALREADY in your hand, with Nostalgia's two plays.
--   Final: Hope 0 + Duplicity 0 + Shock 2 + Nostalgia 0 + Glee 6 + Laziness 4
--   = 12 against 11, a win by one.
-- (The search's winning boards differ only in choices that don't matter: which
-- throwaway card Nostalgia's second pick also takes, which Shock activation
-- takes Glee, and whether Hope is also shocked and replayed -- a free card,
-- since Hope grants its own play.)
-- The tying line: take a stolen 3 instead of playing Laziness: Glee 6 + 3 +
-- Shock 2 = 11, a tie, and a tie goes to the opponent.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'deja-vu',
    'Deja Vu',
    'Each player has two round wins, and your opponent played first this round. Your opponent has Stubbornness, Obsession, Bashfulness and Neurosis in play. You have Hope and Glee in play. You hold Duplicity, Shock, Nostalgia and Laziness, and you have two plays. Win the game this round.',
    'Count how many plays you can really make, and look closely at what each of your own moods would be worth if it were played this round.',
    'hard',
    '[37, 101, 128, 126]', '[124, 92]', '[]', '[]',
    '[]', '[102, 47, 30, 46]',
    2, 2, 0, NULL,
    'win_game', '{}', NULL, 124, 1
);

UPDATE schema_version SET version = '1.61.11' WHERE id = 1;

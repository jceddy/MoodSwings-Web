-- 15th puzzle, "Honor Among Thieves" ("Each player has two round wins and
-- you played first this round. Your opponent has Compulsion, Benevolence and
-- Honor in play -- Honor chose you to go first -- and Indifference in hand.
-- You have Hope and Charity in play, Dignity and Paranoia in hand, Courage on
-- top of your deck, and two plays left. Win the game this round.").
--
-- New puzzle infrastructure:
--   * puzzles.solver_goes_first (default 0): seats the solver as the round's
--     first player instead of the opponent, so a tied score goes to the
--     SOLVER ("ties go to whoever played first") -- goal_type
--     'outscore_opponent'/'win_game' used to hard-code ties to the opponent.
--   * puzzles.prelude_log: the game log's lead-in (what happened at the end
--     of the previous round / start of this one), seeded as the same
--     game_events rows a real game logs -- see
--     GameService::seedPuzzlePreludeLog() for the entry shapes.
--
-- Setup: opponent Compulsion (3) + Benevolence (2) + Honor (3) = 8, one
-- Indifference in hand. Solver: Hope (0) + Charity (1) = 1 in play, Dignity
-- and Paranoia in hand, Courage on top of the deck, two plays (the turn's own
-- plus Hope's, seeded through extra_play_source_card_id).
--
-- The one winning line (an exhaustive search of every legal play order and
-- every target, through the real GameService flow):
--   1. Paranoia on the OPPONENT: Indifference goes to the bottom of the deck
--      and the solver draws Courage.
--   2. Dignity, discarding Courage (a 1): Dignity becomes 5.
--   Final: Hope 0 + Charity 1 + Paranoia 2 + Dignity 5 = 8, tying the
--   opponent's 8 -- and the solver played first, so the tie is theirs.
-- Paranoia on yourself costs Dignity (the only card in hand); Dignity first
-- can't both discard and still afford Paranoia; Courage can't remove anything
-- (nothing in play is worth 5 or more until Dignity is already at 5).
ALTER TABLE puzzles
    ADD COLUMN solver_goes_first TINYINT(1) NOT NULL DEFAULT 0 AFTER opponent_round_wins,
    ADD COLUMN prelude_log JSON NOT NULL DEFAULT ('[]') AFTER solver_goes_first;

INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'honor-among-thieves',
    'Honor Among Thieves',
    'Each player has two round wins, and you played first this round. Your opponent has Compulsion, Benevolence and Honor in play (Honor chose you to go first) and holds Indifference. You have Hope and Charity in play and hold Dignity and Paranoia, with Courage on top of your deck. You have two plays left. Win the game this round.',
    'Count exactly what you could have on the board when your two plays run out -- and remember who wins a tie.',
    'hard',
    '[8, 71]', '[124, 3]',
    '[7,33,36,50,45,27,120,76,16,44,69,42,113,109,5,110,13,119,4,53,90,11,111,19,52,55,83,30,103,129,54,58,63,104,116,21,67,60,72,15,93]',
    '[]',
    '[44]', '[86, 2, 15]',
    2, 2, 1,
    '[{"type":"mood_played","actor":"opponent","card":{"catalog":86,"owner":"opponent","zone":"in_play"},"moves":[{"card":{"catalog":44,"owner":"opponent","zone":"hand"},"from_zone":"hand","from_owner":"solver","to_zone":"hand","to_owner":"opponent"}]},{"type":"round_scored","scores":{"solver":1,"opponent":8},"winner":"opponent","first_player_override":"solver"},{"type":"card_drawn","actor":"solver","card":{"catalog":71,"owner":"solver","zone":"hand"}}]',
    'win_game', '{}', NULL, 124, 1
);

UPDATE schema_version SET version = '1.61.7' WHERE id = 1;

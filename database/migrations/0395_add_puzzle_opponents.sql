-- Issue #524 follow-up (reported live: "I want to up the ante on some of
-- the puzzles, and it would require an opponent"). A puzzle attempt can
-- now optionally seat a SECOND game_player -- a fixed, never-acting
-- opponent board -- alongside the solver. opponent_hand_card_ids/
-- opponent_in_play_card_ids default to '[]' (no opponent, today's
-- solitaire puzzles) for every existing row; a puzzle that sets either
-- one non-empty gets a second seat dealt from them by
-- GameService::createPuzzleAttempt().
--
-- The opponent seat is always PuzzleOpponent (seeded below), the same
-- fixed-roster-of-real-`users`-rows convention 0090_add_practice_bots.sql
-- already established for practice bots -- is_bot = 1 for the same
-- reason (server-driven only, never logged into), though unlike a
-- practice bot this seat's own turn is never actually taken:
-- createPuzzleAttempt() points current_turn_game_player_id at the solver
-- directly, first_game_player_id at PuzzleOpponent (so "who went first"
-- reads correctly for any card that cares, and so ties in the new
-- 'outscore_opponent' goal below go to the opponent, matching the
-- Extended Rules' own "ties go to whoever played first" tiebreak) --
-- PuzzleOpponent's own hand/in-play is simply pre-set board state, not
-- ever the target of a real turn or a real bot decision.
--
-- New goal_type 'outscore_opponent': solved once GameService's own
-- RoundScorer::score()/winner() (the real per-card scoring math, not a
-- puzzle-specific approximation) puts the solver strictly ahead of
-- PuzzleOpponent's board -- see GameService::puzzleSolverOutscoresOpponent().
ALTER TABLE puzzles
    ADD COLUMN opponent_hand_card_ids JSON NOT NULL AFTER deck_card_ids,
    ADD COLUMN opponent_in_play_card_ids JSON NOT NULL AFTER opponent_hand_card_ids,
    MODIFY COLUMN goal_type ENUM('hand_empty', 'card_in_hand', 'card_in_play', 'min_score', 'outscore_opponent') NOT NULL;

UPDATE puzzles SET opponent_hand_card_ids = '[]', opponent_in_play_card_ids = '[]';

INSERT INTO users (username, email, password_hash, share_presence, is_bot, email_verified_at) VALUES
    ('PuzzleOpponent', 'puzzle-opponent@moodswings.invalid', '$2y$12$hAkFbP0LcE1E89W7.8W40O1MJr79Lt8uxyXRTxkTS.09iYsf2IkO2', 0, 1, NOW());

-- One Fell Swoop, redesigned to need an opponent: Complacency is swapped
-- for Ambition, whose own "may discard a card from hand, if you do, may
-- play an additional mood" is the new puzzle's own trap -- ANY card
-- reaching the discard pile this round (not just the acting player's
-- own) is what flips Vulnerability's value from 1 to 7, so taking
-- Ambition's discard to chase one more play boosts the OPPONENT's own
-- score instead of the solver's. Declining it and chaining Friendliness/
-- Kindness/Charity's own unconditional-or-satisfiable grants instead
-- still clears the whole hand in one turn (Friendliness -> Kindness ->
-- Charity -> Ambition), for a final 1+2+2+2 = 7 against PuzzleOpponent's
-- static Vulnerability(1) + Neurosis(5) = 6 -- see
-- php-app/tests/Rules/PuzzleContentTest.php for both lines engine-verified.
UPDATE puzzles SET
    title = 'One Fell Swoop',
    description = 'Your hand: Charity, Ambition, Friendliness, Kindness. Your opponent has Vulnerability and Neurosis in play, went first this round, and you''re both one win from taking the match. Win the game in a single turn -- but think carefully before taking Ambition''s own discard option.',
    difficulty = 'hard',
    starting_hand_card_ids = '[3, 53, 13, 17]',
    starting_in_play_card_ids = '[]',
    deck_card_ids = '[]',
    opponent_hand_card_ids = '[]',
    opponent_in_play_card_ids = '[132, 46]',
    goal_type = 'outscore_opponent',
    goal_params = '{}',
    max_plays = 4
WHERE slug = 'one-fell-swoop';

UPDATE schema_version SET version = '1.56.1' WHERE id = 1;

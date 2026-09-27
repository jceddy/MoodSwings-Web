-- Puzzle collection (issue #524): a curated, freely-replayable library of
-- standalone solitaire puzzles -- a fixed starting hand/board and a
-- specific goal, no live opponent, no daily/weekly cadence, no
-- leaderboard. A puzzle attempt is modeled as a real, minimal `games` row
-- (format = 'puzzle', exactly one seat) so it reuses the entire existing
-- rules engine/persistence layer/board-rendering UI, instead of a
-- parallel bespoke system -- see GameService::createPuzzleAttempt().
--
-- `puzzles` is the static, hand-authored catalog. starting_hand_card_ids/
-- starting_in_play_card_ids/deck_card_ids are ordered JSON arrays of
-- catalog `cards.id` values that createPuzzleAttempt() deals directly
-- into game_cards -- no shuffling, unlike an ordinary game. goal_type/
-- goal_params describe the win condition GameService checks against the
-- live BoardState after every mood played; max_plays is an optional
-- efficiency cap (the puzzle only counts as solved within that many
-- total plays, counted from the existing `mood_played` game_events rows
-- -- no new column needed for that count).
CREATE TABLE IF NOT EXISTS puzzles (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(64) NOT NULL,
    title VARCHAR(120) NOT NULL,
    description TEXT NOT NULL,
    difficulty ENUM('easy', 'medium', 'hard') NOT NULL,
    starting_hand_card_ids JSON NOT NULL,
    starting_in_play_card_ids JSON NOT NULL,
    deck_card_ids JSON NOT NULL,
    goal_type ENUM('hand_empty', 'card_in_hand', 'card_in_play', 'min_score') NOT NULL,
    goal_params JSON NOT NULL,
    max_plays SMALLINT UNSIGNED DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_puzzles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Personal-best tracking only (no full attempt-history log -- the issue
-- itself calls that optional, and skipping it matches the "no
-- leaderboard" simplicity the feature is going for). A row's mere
-- existence means "solved at least once"; best_plays/solve_count update
-- via INSERT ... ON DUPLICATE KEY UPDATE the same way user_lifetime_stats
-- already does elsewhere.
CREATE TABLE IF NOT EXISTS puzzle_solves (
    user_id INT UNSIGNED NOT NULL,
    puzzle_id SMALLINT UNSIGNED NOT NULL,
    first_solved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    best_plays SMALLINT UNSIGNED NOT NULL,
    solve_count INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (user_id, puzzle_id),
    CONSTRAINT fk_puzzle_solves_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_puzzle_solves_puzzle FOREIGN KEY (puzzle_id) REFERENCES puzzles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE games
    MODIFY COLUMN format ENUM('standard', 'duel', 'team', 'closed_team', 'draft', 'puzzle') NOT NULL DEFAULT 'standard',
    ADD COLUMN puzzle_id SMALLINT UNSIGNED DEFAULT NULL AFTER match_game_number;

ALTER TABLE games
    ADD CONSTRAINT fk_games_puzzle FOREIGN KEY (puzzle_id) REFERENCES puzzles (id) ON DELETE SET NULL;

-- New achievement category J (puzzles are their own distinct mode, same
-- reasoning tournaments got their own category F).
INSERT INTO achievements (slug, category, title, description, tier, target, hidden) VALUES
('puzzle-solver', 'J', 'Puzzle Solver', 'Solve your first puzzle', 'Bronze', 1, 0);

-- Debut content: the first 10 hand-authored, engine-verified puzzles.
-- Catalog ids referenced below (all from the base `cards` table): 2
-- Benevolence, 3 Charity, 5 Complacency, 6 Conviction, 13 Friendliness,
-- 16 Idealism, 17 Kindness, 26 Validation, 37 Duplicity, 44 Indifference,
-- 55 Apathy, 64 Envy, 79 Vanity, 83 Boredom, 114 Eagerness, 128 Nostalgia,
-- 133 Wonder. See php-app/tests/Rules/PuzzleContentTest.php, which builds
-- each of these exact definitions and asserts the intended solution (and,
-- where noted below, a tempting wrong line) against the real engine.
INSERT INTO puzzles (slug, title, description, difficulty, starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, goal_type, goal_params, max_plays, active) VALUES
(
    'one-fell-swoop',
    'One Fell Swoop',
    'Your hand: Charity, Complacency, Friendliness, Kindness. Clear your entire hand in a single turn -- the order you play them in matters more than it looks.',
    'hard',
    '[3, 5, 13, 17]', '[]', '[]',
    'hand_empty', '{}', 4, 1
),
(
    'turn-it-on-yourself',
    'Turn It On Yourself',
    'Your hand: just Conviction, and no moods in play yet. Conviction makes its target put a mood on the bottom of the deck and draw a card -- find a legal target and draw a fresh card into your hand.',
    'easy',
    '[6]', '[]', '[16]',
    'card_in_hand', '{"catalog_card_id": 16}', 1, 1
),
(
    'chain-reaction',
    'Chain Reaction',
    'Your hand: Charity, Idealism, Indifference. Clear your whole hand this turn -- one of these three cards has no ability of its own, so it can''t open the chain.',
    'easy',
    '[3, 16, 44]', '[]', '[]',
    'hand_empty', '{}', 3, 1
),
(
    'color-chain',
    'Color Chain',
    'Your hand: Benevolence, Duplicity, Idealism. Clear your whole hand this turn -- Benevolence only lets you follow it with a mood that DOESN''T share a color with anything you have in play.',
    'easy',
    '[2, 37, 16]', '[]', '[]',
    'hand_empty', '{}', 3, 1
),
(
    'vain-effort',
    'Vain Effort',
    'Your hand: Charity, Idealism, Vanity. Vanity is worth a lot more once your hand is completely empty -- reach a total board value of at least 10 this turn.',
    'medium',
    '[3, 16, 79]', '[]', '[]',
    'min_score', '{"target": 10}', 3, 1
),
(
    'wonders-choice',
    'Wonder''s Choice',
    'Already in play: Complacency, Idealism, Indifference. In hand: Wonder. Wonder''s value grows with every mood of one color you choose when you play it -- pick the color that''s actually worth the most here, and reach a total board value of at least 12.',
    'hard',
    '[133]', '[5, 16, 44]', '[]',
    'min_score', '{"target": 12}', 1, 1
),
(
    'envious-timing',
    'Envious Timing',
    'Your hand: Envy, Indifference. Envy can only be played by discarding one of your OWN moods already in play -- with an empty board, it can''t be played at all yet. Get Envy into play.',
    'medium',
    '[64, 44]', '[]', '[]',
    'card_in_play', '{"catalog_card_id": 64}', NULL, 1
),
(
    'validation-loop',
    'Validation Loop',
    'Already in play: Validation. In hand: Idealism, Duplicity, Boredom. Validation quietly grants you another play every time you play a mood worth 0 or 1 -- clear your whole hand this turn.',
    'medium',
    '[16, 37, 83]', '[26]', '[]',
    'hand_empty', '{}', 3, 1
),
(
    'kindred-colors',
    'Kindred Colors',
    'Your hand: Duplicity, Eagerness, Nostalgia. Clear your whole hand this turn -- Eagerness only lets you follow it with a mood that DOES share a color with something you have in play.',
    'easy',
    '[114, 37, 128]', '[]', '[]',
    'hand_empty', '{}', 3, 1
),
(
    'the-lesser-sacrifice',
    'The Lesser Sacrifice',
    'Already in play: Boredom, Apathy. In hand: just Conviction. Conviction has to send SOME mood to the bottom of the deck when you play it, including itself -- choose wisely and reach a total board value of at least 8.',
    'hard',
    '[6]', '[83, 55]', '[]',
    'min_score', '{"target": 8}', 1, 1
);

UPDATE schema_version SET version = '1.56.0' WHERE id = 1;

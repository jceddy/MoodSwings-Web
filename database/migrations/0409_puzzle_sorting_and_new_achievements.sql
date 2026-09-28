-- Issue #524 follow-up (reported live): three small puzzle/achievement
-- tweaks bundled together.
--
-- 1) Puzzles are now listed sorted by difficulty (Easy, Medium, Hard) --
-- GameService::listActivePuzzles()'s own ORDER BY now reads
-- `p.difficulty, p.id` instead of just `p.id`. No schema change needed:
-- puzzles.difficulty is declared ENUM('easy', 'medium', 'hard')
-- (migration 0394), and MySQL already sorts an ENUM by its declaration
-- index rather than alphabetically, so a plain ORDER BY on it is already
-- Easy < Medium < Hard.
--
-- 2) The existing "Puzzle Solver" achievement now requires solving a
-- puzzle WITHOUT ever opening its Hint dialog during that attempt -- see
-- the new games.puzzle_hint_viewed column below, set by the new
-- POST /games/puzzle-hint-viewed endpoint the frontend's Hint button now
-- calls the moment it's clicked. AchievementService::onPuzzleSolved()
-- only calls unlock() for 'puzzle-solver' when that flag is false for
-- the solving attempt -- unlock() is idempotent, so a player's first
-- several solves all using a hint don't burn their shot at it; it just
-- waits for a later solve (of any puzzle) that doesn't.
--
-- 3) A new achievement, "Puzzle Enthusiast" ('Solve 10 puzzles'),
-- category J alongside 'puzzle-solver', target 10, tier Silver (one
-- step up from puzzle-solver's own Bronze). Counts DISTINCT puzzles
-- solved -- one bumpProgress() call per puzzle the very first time it's
-- solved by that user, gated on a SELECT against puzzle_solves taken
-- before that solve's own upsert, never on a repeat solve of an
-- already-solved puzzle.
ALTER TABLE games
    ADD COLUMN puzzle_hint_viewed TINYINT(1) NOT NULL DEFAULT 0 AFTER puzzle_id;

UPDATE achievements SET
    description = 'Solve your first puzzle without asking for a hint.'
WHERE slug = 'puzzle-solver';

INSERT INTO achievements (slug, category, title, description, tier, target, hidden) VALUES
('puzzle-enthusiast', 'J', 'Puzzle Enthusiast', 'Solve 10 puzzles', 'Silver', 10, 0);

UPDATE schema_version SET version = '1.56.15' WHERE id = 1;

-- Issue #524 follow-up (reported live: "show the puzzle goal in the game
-- display"). Pure code change, no schema change -- GameService::getState()
-- now also fetches puzzles.description (already stored, already shown on
-- the puzzle list before the attempt starts) alongside the existing
-- puzzle hint fetch, exposing it as game.puzzle_description so the web
-- board can show it unconditionally (see renderPuzzleGoal() in game.js),
-- unlike the hint, which stays behind its own button. Same "schema-
-- version-only migration" pattern as every other pure-code-change follow-
-- up in this arc (0387-0393, 0410-0411, 0415) -- see php-app/README.md.
UPDATE schema_version SET version = '1.57.1' WHERE id = 1;

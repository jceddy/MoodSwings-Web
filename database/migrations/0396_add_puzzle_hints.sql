-- Issue #524 follow-up (reported live: "Add a 'hint' button when in the
-- puzzle, that pops up a dialog with the following hint"). Puzzles can now
-- optionally store a short hint string, shown by the frontend behind a
-- "Hint" button on the puzzle's own board (see GameService::buildGameState()'s
-- new puzzle_hint field) rather than always visible, so it doesn't spoil the
-- puzzle for anyone who doesn't need it. NULL for every puzzle that has no
-- hint -- the frontend hides the button entirely in that case.
ALTER TABLE puzzles
    ADD COLUMN hint VARCHAR(500) NULL AFTER description;

-- One Fell Swoop's description also drops its own inline "but think
-- carefully..." spoiler now that the hint button carries that warning
-- instead.
UPDATE puzzles SET
    description = 'Your hand: Charity, Ambition, Friendliness, Kindness. Your opponent has Vulnerability and Neurosis in play, went first this round, and you''re both one win from taking the match. Win the game in a single turn.',
    hint = 'Think carefully before taking Ambition''s discard option.'
WHERE slug = 'one-fell-swoop';

UPDATE schema_version SET version = '1.56.2' WHERE id = 1;

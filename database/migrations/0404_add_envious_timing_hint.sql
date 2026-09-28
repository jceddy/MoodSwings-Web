-- Issue #524 follow-up (reported live: "For Envious Timing: Change the
-- description to: Your hand: Envy, Indifference. Get Envy into play. And
-- add a hint: Envy can only be played by moving one of your OWN moods
-- already in play to the discard pile -- with an empty board, it can't
-- be played at all yet."). Same pattern as migrations 0396/0401/0403:
-- trims the puzzle's own inline explanation of Envy's discard cost out of
-- the description, moving it behind the optional Hint button instead.
UPDATE puzzles SET
    description = 'Your hand: Envy, Indifference. Get Envy into play.',
    hint = 'Envy can only be played by moving one of your OWN moods already in play to the discard pile -- with an empty board, it can''t be played at all yet.'
WHERE slug = 'envious-timing';

UPDATE schema_version SET version = '1.56.10' WHERE id = 1;

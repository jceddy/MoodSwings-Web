-- Issue #524 follow-up (reported live: "Change Wonder's Choice description
-- to: Already in play: Indifference. In discard: Complacency, Idealism. In
-- hand: Wonder. Reach a total board value of at least 8. Add this hint:
-- Which cards does Wonder count?"). Same pattern as migration 0396 (One
-- Fell Swoop) and 0401 (Vain Effort): trims the puzzle's own inline
-- explanation of its central mechanic out of the description, moving it
-- behind the optional Hint button instead.
UPDATE puzzles SET
    description = 'Already in play: Indifference. In discard: Complacency, Idealism. In hand: Wonder. Reach a total board value of at least 8.',
    hint = 'Which cards does Wonder count?'
WHERE slug = 'wonders-choice';

UPDATE schema_version SET version = '1.56.9' WHERE id = 1;

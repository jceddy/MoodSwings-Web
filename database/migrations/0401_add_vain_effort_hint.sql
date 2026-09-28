-- Issue #524 follow-up (reported live): trims "Vain Effort"'s own inline
-- spoiler about Vanity's dynamic value out of its description, moving it
-- behind the same optional Hint button migration 0396 introduced for One
-- Fell Swoop.
UPDATE puzzles SET
    description = 'Your hand: Charity, Friendliness, Ambition, and Vanity. Reach a total board value of exactly 12 this turn.',
    hint = 'Vanity is worth a lot more once your hand is completely empty.'
WHERE slug = 'vain-effort';

UPDATE schema_version SET version = '1.56.7' WHERE id = 1;

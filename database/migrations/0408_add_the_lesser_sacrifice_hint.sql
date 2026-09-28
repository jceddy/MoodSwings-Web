-- Issue #524 follow-up (reported live: "For The Lesser Sacrifice, move
-- the Conviction targeting explanation to a hint."). Same pattern as
-- migrations 0396/0401/0403/0404/0407: trims the puzzle's own inline
-- explanation of its central mechanic out of the description, moving
-- it behind the optional Hint button instead.
UPDATE puzzles SET
    description = 'Already in play: Boredom, Apathy. In hand: just Conviction. Reach a total board value of at least 8.',
    hint = 'Conviction has to send SOME mood to the bottom of the deck when you play it, including itself -- choose wisely.'
WHERE slug = 'the-lesser-sacrifice';

UPDATE schema_version SET version = '1.56.14' WHERE id = 1;

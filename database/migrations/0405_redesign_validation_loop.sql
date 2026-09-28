-- Issue #524 follow-up (reported live: "For Validation Loop: exchange
-- Idealism and Duplicity for 0/1 point cards that don't themselves grant
-- an extra play. Move 'Validation quietly grants you another play every
-- time you play a mood worth 0 or 1.' from the description to a hint.").
--
-- Idealism and Duplicity both grant an extra play from their OWN printed
-- ability ("you may play an additional mood this turn"), independent of
-- Validation being in play at all -- so the puzzle's own intended lesson
-- (Validation's reactive, easy-to-miss "quietly grants another play"
-- trigger) was never actually load-bearing; the two cards' own grants
-- already supplied enough extra plays to solve it regardless. Swapped for
-- Sadness(0, black) and Vulnerability(1, green), both purely
-- value-scaling cards with no "after playing" ability of their own (see
-- SadnessEffect/VulnerabilityEffect) -- so ValidationEffect::
-- reactToAnotherPlay()'s own reactive grant (checked against printed
-- baseValue, so Sadness's/Vulnerability's dynamic value never matters
-- here) is now the ONLY source of the two extra plays the solve needs.
-- The inline explanation of that mechanic moves behind the Hint button
-- instead, same pattern as every other puzzle in this arc. See
-- php-app/tests/Rules/PuzzleContentTest.php for both the solve and the
-- Boredom-first stall re-verified against the real engine.
UPDATE puzzles SET
    description = 'Already in play: Validation. In hand: Sadness, Vulnerability, Boredom. Clear your whole hand this turn.',
    hint = 'Validation quietly grants you another play every time you play a mood worth 0 or 1.',
    starting_hand_card_ids = '[74, 132, 83]'
WHERE slug = 'validation-loop';

UPDATE schema_version SET version = '1.56.11' WHERE id = 1;

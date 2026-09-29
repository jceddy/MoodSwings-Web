-- Issue #524 follow-up (reported live: "For Wonder's Choice: Start with
-- Indifference in play and Complacency and Idealism in discard. Change
-- the goal score to 8.").
--
-- Wonder's own effect counts matches in BOTH the in-play zone and the
-- discard pile (WonderEffect::computeValue()), so moving Complacency(4,
-- white)/Idealism(0, white) from in-play into the discard pile doesn't
-- change how many white matches choosing 'white' finds -- still 2 --
-- but it does drop their own base values out of the board-value total,
-- since only Indifference(4, blue) remains in play alongside Wonder
-- itself. New total choosing white: Indifference(4) + Wonder
-- (0 + 2*2 = 4) = 8, exactly the new target. Choosing blue instead still
-- falls short: Indifference(4) + Wonder (0 + 2*1 = 2) = 6 < 8. See
-- php-app/tests/Rules/PuzzleContentTest.php for both lines re-verified
-- against the real engine.
UPDATE puzzles SET
    description = 'Already in play: Indifference. In discard: Complacency, Idealism. In hand: Wonder. Wonder''s value grows with every mood of one color you choose when you play it -- pick the color that''s actually worth the most here, and reach a total board value of at least 8.',
    starting_in_play_card_ids = '[44]',
    starting_discard_card_ids = '[5, 16]',
    goal_params = '{"target": 8}'
WHERE slug = 'wonders-choice';

UPDATE schema_version SET version = '1.56.8' WHERE id = 1;

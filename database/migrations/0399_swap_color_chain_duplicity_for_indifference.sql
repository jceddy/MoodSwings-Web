-- Issue #524 follow-up (reported live: "For Color Chain, we need to swap
-- Duplicity out for a card that doesn't itself give an extra play, maybe
-- Indifference"). Duplicity's own unconditional "you may play an
-- additional mood this turn" made the puzzle's own middle/last two cards
-- interchangeable once Benevolence's own color rule was satisfied -- any
-- differently-colored second card worked, since Duplicity's own grant
-- covered the third play regardless of order.
--
-- With Indifference (no ability at all) in Duplicity's old slot, only
-- ONE of the six possible play orders actually clears the hand: Idealism
-- (unconditional extra play) must go FIRST, Benevolence (its own
-- extra play conditional on "doesn't share a color with any of your
-- moods") second -- satisfied since Indifference/Idealism haven't muddied
-- the board with a shared color yet at THAT point (Idealism's own white
-- is already down, but Indifference, played last, is blue, satisfying
-- Benevolence's own restriction) -- and Indifference last, since it has
-- no grant of its own to waste on anything. Playing Benevolence first
-- still illegally strands Idealism if Indifference is played second (the
-- puzzle's own original "different color" lesson survives), but now ALSO
-- stalls one play short of clearing the hand even when the color rule
-- itself is respected, if Idealism was never played early enough to
-- backfill the third play. See
-- php-app/tests/Rules/PuzzleContentTest.php for every line
-- engine-verified.
UPDATE puzzles SET
    description = 'Your hand: Benevolence, Indifference, Idealism. Clear your whole hand this turn -- Benevolence only lets you follow it with a mood that DOESN''T share a color with anything you have in play.',
    difficulty = 'medium',
    starting_hand_card_ids = '[2, 44, 16]'
WHERE slug = 'color-chain';

UPDATE schema_version SET version = '1.56.5' WHERE id = 1;

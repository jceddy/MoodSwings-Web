-- Issue #524 follow-up (reported live: "For Kindred Colors: Trade
-- Duplicity for Charity, and trade Nostalgia for Laziness. Move
-- 'Eagerness only lets you follow it with a mood that DOES share a
-- color with something you have in play.' from the description to a
-- hint.").
--
-- Duplicity(blue) is replaced with Charity(white, unconditional "you may
-- play an additional mood"), and Nostalgia(green) is replaced with
-- Laziness(green, vanilla -- no ability at all, one of the five flat-4
-- commons). This inverts which grant has to be saved for last: Charity's
-- own UNCONDITIONAL grant must be spent early (on Eagerness itself,
-- since Eagerness's printed color, green, doesn't match Charity's white)
-- while Eagerness's own CONDITIONAL grant ("...if it shares a color with
-- one of your moods") has to be saved for the very end, since it's the
-- only remaining source of a third play and Laziness (green) is the only
-- card left that can satisfy it once Eagerness itself is in play:
-- Charity -> Eagerness -> Laziness. Playing Eagerness first instead
-- stalls one card short -- its own conditional grant is immediately
-- spent on the only qualifying card (Laziness, green), leaving Charity
-- (white) with no further grant to use it. See
-- php-app/tests/Rules/PuzzleContentTest.php for both lines re-verified
-- against the real engine.
UPDATE puzzles SET
    description = 'Your hand: Charity, Eagerness, Laziness. Clear your whole hand this turn.',
    hint = 'Eagerness only lets you follow it with a mood that DOES share a color with something you have in play.',
    starting_hand_card_ids = '[3, 114, 126]'
WHERE slug = 'kindred-colors';

UPDATE schema_version SET version = '1.56.13' WHERE id = 1;

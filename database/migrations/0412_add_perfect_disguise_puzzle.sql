-- Issue #524 follow-up ("help me come up with a new puzzle centered
-- around Creativity"): an 11th puzzle, "Perfect Disguise," built around a
-- subtlety that's already caused a real bug once (the Conviction/Bliss
-- bot-targeting fix, migration 0406): a Creativity copy's color, for
-- anything that cares about color (like Bliss's own scoring bonus), is
-- whatever it's COPYING, not Creativity's own printed blue.
--
-- Hand: Joy (125, green, 3), Bliss (108, green, 2), Creativity (32,
-- blue, 0), Eagerness (114, green, filler -- never played, only
-- discarded), Indifference (44, blue, filler -- never played, only
-- discarded). Opponent in play: Complacency (5), Apathy (55), Boredom
-- (83), Laziness (126) -- all deliberately UNREGISTERED, ability-less
-- catalog rows (no PairedColorThresholdEffect, no board-reactive value of
-- any kind), so the opponent's own total is a fixed 4+4+4+4 = 16
-- regardless of what the solver's board looks like.
--
-- Correct line:
--   1. Play Joy -- in play: Joy(3). Score 3.
--   2. Play Bliss, discarding EAGERNESS (green) to pay its cost --
--      blissColor = green. In play: Joy(3), Bliss(2), both green. Base
--      sum 5, Bliss bonus 2*(3+2) = 10, total 15 -- still short of 16,
--      so Creativity is NOT optional flavor, it's the card that actually
--      closes the gap.
--   3. Play Creativity, copy_card_id = Joy (already in play) --
--      Creativity becomes an exact copy of Joy: green, value 3. In play:
--      Joy(3), Bliss(2), Creativity-as-Joy(3), all three green. Base sum
--      8, Bliss bonus 2*8 = 16, total 24 > 16 -- solved.
--
-- Tempting wrong line: discard INDIFFERENCE (blue) to Bliss instead of
-- Eagerness, on the theory that Creativity's own printed blue needs a
-- blue-keyed Bliss to benefit. blissColor = blue; nothing ever actually
-- IS blue in play (Creativity becomes green the moment it copies Joy),
-- so the bonus is always 0. Final total after all three plays: base sum
-- 8, bonus 0, total 8 < 16 -- never solves, no matter how many mini-turns
-- are spent on it.
--
-- No card here grants an immediate extra play (Joy's own "extra play"
-- is explicitly NEXT turn, not this one), so the intended solution
-- genuinely spans three separate mini-turns -- max_plays is left NULL,
-- per the "a puzzle whose intended solution needs a mid-attempt turn
-- refresh simply leaves max_plays unset" convention (php-app/README.md).
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'perfect-disguise',
    'Perfect Disguise',
    'Your hand: Joy, Bliss, Creativity, Eagerness, Indifference. Your opponent has Complacency, Apathy, Boredom, and Laziness in play. Score higher than your opponent this round.',
    'Once Creativity copies another mood, it takes on that mood''s own color -- not its own printed blue -- for anything that cares about color.',
    'hard',
    '[125, 108, 32, 114, 44]', '[]', '[]', '[]',
    '[]', '[5, 55, 83, 126]',
    'outscore_opponent', '{}', NULL, NULL, 1
);

UPDATE schema_version SET version = '1.56.18' WHERE id = 1;

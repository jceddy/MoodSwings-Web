-- Perfect Disguise redesign (reported live while testing: the puzzle's
-- own description said "beat the opponent this round" but the shipped
-- solution (migration 0412) actually needed three separate mood plays --
-- Joy, then Bliss, then Creativity -- across multiple mini-turns, since
-- none of those three grant an immediate extra play. The UI gives no
-- visible cue when a puzzle mini-turn silently refreshes, so the attempt
-- looked stuck/confusing between plays.
--
-- Fix: pre-place Joy in play and bank its own extra play from the start,
-- the same device "Turn It On Yourself" (migration 0411's era) already
-- uses for "you already played Joy earlier" framing. That drops the
-- puzzle to exactly two real plays -- Bliss, then Creativity -- covered
-- by a single starting plays_remaining of 2 (1 base + 1 banked), so the
-- whole solution now resolves within one continuous turn, no mid-attempt
-- refresh involved. The underlying lesson is untouched: Creativity still
-- copies the already-in-play Joy and takes on Joy's green, not its own
-- printed blue, for Bliss's own bonus to see.
--
-- Correct line (one turn, plays_remaining starts at 2):
--   1. Play Bliss, discarding Eagerness (green) -- blissColor = green.
--      In play: Joy(3), Bliss(2), both green. Base sum 5, Bliss bonus
--      2*(3+2) = 10, total 15 -- still short of the opponent's fixed 16.
--   2. Play Creativity, copy_card_id = Joy (already in play) --
--      Creativity becomes green, value 3. In play: Joy(3), Bliss(2),
--      Creativity-as-Joy(3), all three green. Base sum 8, Bliss bonus
--      2*8 = 16, total 24 > 16 -- solved, exactly at plays_remaining 0.
--
-- Tempting wrong line unchanged: discarding Indifference (blue) to
-- Bliss's cost instead locks blissColor = blue, and nothing is ever
-- actually blue in play once Creativity copies Joy, so the bonus stays
-- 0 -- final total 8, still short of 16, and now also out of plays (both
-- real cards spent) within the very same turn, so it's immediately and
-- visibly over rather than trailing off into more mini-turns.
--
-- max_plays is now set to 2, matching the puzzle's own play budget --
-- previously left NULL specifically because the multi-turn solution had
-- no clean single-turn play count to cap at.
UPDATE puzzles SET
    description = 'Your hand: Bliss, Creativity, Eagerness, Indifference. Joy is already in play, and you have one extra play banked from it. Your opponent has Complacency, Apathy, Boredom, and Laziness in play. Score higher than your opponent this turn.',
    starting_hand_card_ids = '[108, 32, 114, 44]',
    starting_in_play_card_ids = '[125]',
    extra_play_source_card_id = 125,
    max_plays = 2
WHERE slug = 'perfect-disguise';

UPDATE schema_version SET version = '1.56.19' WHERE id = 1;

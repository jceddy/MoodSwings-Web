-- The Lesser Sacrifice redesign (reported live while testing: the puzzle
-- started already AT its own goal -- Boredom(4) + Apathy(4) = 8, and the
-- goal was min_score >= 8 -- so a player could simply pass and already be
-- "solved" without ever touching Conviction. Passing doesn't actually
-- check the puzzle goal (only a real play does), so this wasn't directly
-- exploitable, but it left the puzzle starting in a degenerate state with
-- no real tension, and the surrounding pass/resign UX around a puzzle
-- that's already at its goal is rough in its own right (separate from
-- this content fix).
--
-- Fix: raise the bar so simply having Conviction's forced sacrifice net
-- out to zero isn't enough on its own -- it now takes a second play too,
-- landing on the exact target only via the "lesser sacrifice" choice.
-- Joy joins the starting board with its own extra play pre-banked (same
-- device as migration 0413's Perfect Disguise redesign), and Conviction's
-- own forced draw is now guaranteed to matter: the deck holds exactly one
-- card, Courage(7, value 1), so whoever ends up owning the sacrificed
-- mood draws it and can spend the banked second play on it.
--
-- Board at the start: Boredom(83, 4) + Apathy(55, 4) + Joy(125, 3) = 11,
-- still short of the new target of 12. plays_remaining starts at 2 (1
-- base + 1 banked from Joy).
--
-- Correct line:
--   1. Play Conviction(6, value 2) -- board temporarily 11 + 2 = 13, then
--      its forced effect must send SOME mood to the bottom of the deck.
--      Targeting Conviction ITSELF (the lesser sacrifice) removes exactly
--      what was just added, back to 11 -- a net loss of nothing, since
--      Conviction never really "belonged" to the permanent board -- and
--      draws Courage into hand. Still short of 12, so this isn't the
--      whole puzzle by itself; plays_remaining is now 1.
--   2. Play Courage(7, value 1), declining its own optional "choose up to
--      two players" effect (no mood in play is ever 5+ here, so there's
--      nothing to target anyway) -- board 11 + 1 = 12. Solved.
--
-- Any other Conviction target is strictly worse and never recovers:
-- targeting Boredom or Apathy nets 13 - 4 = 9, +1 from Courage = 10;
-- targeting Joy nets 13 - 3 = 10, +1 = 11. Both plays are spent either
-- way, so there's no way to make up the difference afterward -- the
-- puzzle is stuck, never solved, exactly like the original version's
-- "targeting a higher-value mood falls short" trap.
UPDATE puzzles SET
    description = 'Already in play: Boredom, Apathy, Joy (and you have one extra play banked from it). In hand: just Conviction. Reach a total board value of at least 12.',
    starting_in_play_card_ids = '[83, 55, 125]',
    deck_card_ids = '[7]',
    extra_play_source_card_id = 125,
    goal_params = '{"target": 12}',
    max_plays = 2
WHERE slug = 'the-lesser-sacrifice';

UPDATE schema_version SET version = '1.56.20' WHERE id = 1;

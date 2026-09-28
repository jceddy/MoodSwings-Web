-- Issue #524 follow-up (reported live: "For Vain Effort, let's take out
-- Idealism and replace it with both Friendliness and Ambition. Change the
-- goal to exactly 12 points."). Vanity's own dynamic value ("+1 per own
-- mood, or +3 per own mood once your hand is completely empty") is
-- unchanged -- what changes is how the other three cards fight over the
-- limited number of extra plays available:
--
-- Charity grants an unconditional extra play; Friendliness's own grant
-- is restricted to a mood with an even (0/2/4/6) printed value; Ambition
-- offers ANOTHER extra play, but only by discarding a card from hand
-- first. With only 4 cards total and just 3 ways to earn an extra play
-- (Charity unconditional, Friendliness conditional, Ambition's own
-- discard-gated one), playing all 4 cards outright is never possible in
-- one turn -- the only way to also clear the hand (letting Vanity's own
-- value triple) is to DISCARD Friendliness via Ambition's own cost
-- instead of ever playing it: Charity + Ambition (discarding
-- Friendliness) + Vanity = 1 + 2 + (3 moods x 3, hand now empty) =
-- 1 + 2 + 9 = 12 exactly. Playing Friendliness instead of sacrificing it
-- caps the total at just Charity(1) + Friendliness(2) + Ambition(2) = 5,
-- with nothing left to grant Vanity's own play -- a decisive shortfall,
-- not just a smaller win. See php-app/tests/Rules/PuzzleContentTest.php
-- for every line engine-verified.
UPDATE puzzles SET
    description = 'Your hand: Charity, Friendliness, Ambition, and Vanity. Vanity is worth a lot more once your hand is completely empty -- reach a total board value of exactly 12 this turn.',
    difficulty = 'hard',
    starting_hand_card_ids = '[3, 13, 53, 79]',
    goal_params = '{"target": 12}',
    max_plays = 3
WHERE slug = 'vain-effort';

UPDATE schema_version SET version = '1.56.6' WHERE id = 1;

-- Issue #524 follow-up (reported live: "For Chain Reaction -- The opponent
-- has Benevolence and Happiness in play... You have Charity, Idealism,
-- Indifference, and Animosity in hand. Win the game in one turn."). The
-- literal request turned out to have a trivial one-move solve: Animosity's
-- own "value is 5 if any opponent has 3+ cards in hand" is already true
-- from turn 1 (a puzzle's opponent seat never acts, so their hand size
-- never changes), and 5 alone already clears Benevolence(2) + Happiness(2)
-- = 4 without ever touching the chain. Landed on an alternate
-- configuration (confirmed live, and deliberately NOT reusing One Fell
-- Swoop's own discard-pile/Vulnerability trap) keeping the same shape --
-- chain the two white extra-play cards, then choose between a safe filler
-- and a fourth card that looks strictly better but punishes you:
--
-- Self-Loathing (flat 6, "to play this card, put one or more of your
-- moods into the discard pile. If you can't do that, you can't play this
-- card.") is genuinely ILLEGAL to play before at least one of Charity/
-- Idealism is already down (nothing to discard yet), so unlike
-- Animosity, there's no one-move shortcut here at all -- see
-- IllegalPlayException in php-app/tests/Rules/PuzzleContentTest.php's own
-- new test.
--
-- Superiority ("value is 7 if you have more moods than each other
-- player") is the new trap trigger -- not a discard-pile reaction this
-- time, but a mood-COUNT one. The opponent holds exactly 3 moods
-- (Superiority + Malice + Spite, both otherwise-inert fillers -- their
-- own "after playing" text never fires for a mood dealt straight into
-- play). Charity+Idealism+Indifference leaves the solver with 3 moods
-- too (tied, not fewer) -- Superiority stays at its own base value.
-- Self-Loathing's own discard cost, though, SHRINKS the solver's own
-- board back down to 2 moods (whichever of Charity/Idealism was
-- discarded is no longer in play) -- now strictly fewer than the
-- opponent's 3, so Superiority spikes to 7.
--
-- Solved line: Charity(1) + Idealism(0) + Indifference(4) = 5 vs
-- Superiority(3, base) + Malice(0) + Spite(1) = 4.
-- Playing Indifference alone (skipping the chain) leaves the solver with
-- only 1 mood against the opponent's 3, spiking Superiority to 7
-- (opponent totals 8) against the solver's own 4 -- a decisive loss, not
-- just a tie, so the "vanilla card can't open the chain" lesson from the
-- original solitaire puzzle survives (and hits harder than before).
-- Trap line: Charity + Idealism + Self-Loathing (discarding either one to
-- pay its cost) leaves the solver with only 2 moods -- fewer than the
-- opponent's 3 -- spiking Superiority to 7 (opponent totals 8) against
-- the solver's own 6 or 7, a loss either way despite Self-Loathing's own
-- tempting flat 6. See php-app/tests/Rules/PuzzleContentTest.php for all
-- lines engine-verified.
UPDATE puzzles SET
    description = 'Your hand: Charity, Idealism, Indifference, and Self-Loathing. Your opponent has Superiority, Malice, and Spite in play, went first this round, and you''re both one win from taking the match. Win the game in a single turn.',
    difficulty = 'hard',
    starting_hand_card_ids = '[3, 16, 44, 75]',
    starting_in_play_card_ids = '[]',
    deck_card_ids = '[]',
    opponent_hand_card_ids = '[]',
    opponent_in_play_card_ids = '[77, 68, 76]',
    goal_type = 'outscore_opponent',
    goal_params = '{}',
    max_plays = 3
WHERE slug = 'chain-reaction';

UPDATE schema_version SET version = '1.56.4' WHERE id = 1;

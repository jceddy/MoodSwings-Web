-- Rationalization puzzle ("I want to add a puzzle focused around
-- rationalization -- you need to hit a certain point total and it requires
-- playing out all of the cards in your hand as well as all of the cards in
-- the opponent's hand, in a specific sequence, using rationalization to
-- steal the opponent's hand once it is the last card in your hand"): the
-- 13th puzzle, "Hostile Takeover." No new puzzle infrastructure -- a plain
-- min_score goal, max_plays 7 (so the whole chain has to happen in one
-- unbroken turn).
--
-- Setup: the solver holds Rationalization (49) plus three cards --
-- Validation (26), Charity (3), Friendliness (13) -- and the opponent holds
-- Kindness (17), Benevolence (2), Eagerness (114). Nothing is in play and
-- there is no deck (so Rationalization's "refresh" mode just reshuffles the
-- same hand). Goal: score 13, which is exactly the seven cards' printed
-- values added together (1+1+2+3 + 2+2+2) -- every card has to reach the
-- table.
--
-- Rationalization's "rotate" mode swaps whole hands (with two players, the
-- solver's hand for the opponent's). Played while other cards are still in
-- the solver's hand, it GIVES those cards away; played as the last card, it
-- costs nothing and the solver walks off with all three of the opponent's.
-- Rationalization itself grants no extra play, so the chain has to be
-- stocked with exactly the right plays beforehand -- and every extra play in
-- this set has a string attached, which is what makes the order unique.
--
-- The one winning card order (verified by exhaustive search of the real
-- GameService flow, including every choice of which play grant to spend --
-- see the grant_source_card_id notes in php-app/README.md):
--   1. Validation -- grants a play; while in play, each later play of a 0 or
--      1 gives another.
--   2. Charity -- a 1, so Validation pays out too: Charity's own play plus
--      Validation's second.
--   3. Friendliness -- its grant only covers a card with an even printed
--      value (0/2/4/6). Nothing in the solver's own hand needs it; it is
--      banked for the stolen cards.
--   4. Rationalization, rotate -- the last card in hand. The solver now
--      holds Kindness, Benevolence and Eagerness (all 2s), with only
--      Friendliness's even-value play left to spend.
--   5. Benevolence -- even, so Friendliness pays for it. Its own grant works
--      only for a card sharing no color with any of the solver's moods
--      (white and blue so far) -- so Kindness is out, Eagerness is in.
--   6. Eagerness -- green, so Benevolence's grant pays for it. Its own grant
--      works only for a card sharing a color with one of the solver's moods
--      -- and Kindness is white.
--   7. Kindness, using Eagerness's grant: 13 points.
--
-- The tempting wrong lines, all verified to fall short:
--   * Rationalization the moment it is playable (Validation, Charity, then
--     Rationalization): it looks like the point of the puzzle, but
--     Friendliness is still in hand and goes to the opponent -- 11 points,
--     one card never played.
--   * Right up to the steal, then the wrong stolen card first, each
--     stranding one card at 11 points: Eagerness then Kindness strands
--     Benevolence (Kindness's grant needs an odd value and Benevolence is a
--     2); Eagerness then Benevolence strands Kindness (white, so it shares
--     a color with the solver's moods, which Benevolence's grant forbids);
--     and Kindness first strands everything left for the same odd-value
--     reason.
--   * Every other ordering of the seven cards: the search found none that
--     reaches 13.
--
-- Deliberately only seven cards, none of which asks for a choice beyond
-- Rationalization's mode: Duplicity and Fear were tried in the pool and
-- either produced multiple winning orders or made the solution
-- unreadable.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'hostile-takeover',
    'Hostile Takeover',
    'Score 13 points this turn. Your hand: Rationalization, Validation, Charity and Friendliness. Your opponent holds Kindness, Benevolence and Eagerness.',
    '13 is all seven cards'' values added together -- every one has to reach the table. Rationalization''s hand swap only costs you nothing once it is the last card in your hand, and each extra play your cards grant comes with conditions: read every one.',
    'hard',
    '[26, 3, 13, 49]', '[]', '[]', '[]',
    '[17, 2, 114]', '[]',
    0, 0,
    'min_score', '{"target": 13}', 7, NULL, 1
);

UPDATE schema_version SET version = '1.59.7' WHERE id = 1;

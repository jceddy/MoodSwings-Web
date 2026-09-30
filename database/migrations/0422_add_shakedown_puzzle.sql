-- Issue #524 follow-up ("I want to add another puzzle, centered around the
-- card Intimidation"): a 12th puzzle, "Shakedown."
--
-- The premise that makes Intimidation puzzle-worthy: the TARGET picks which
-- card to reveal, and the puzzle opponent (like every bot) always gives up
-- its WORST card first -- lowest draft_priority_score, then lowest printed
-- value (BotChoiceResolver::ownResourceCandidateValue()). So a single
-- Intimidation only ever takes the junk; the card you actually want has to
-- come second.
--
-- Hand: Duplicity (37), Intimidation (67). Opponent's hand: Charity (3,
-- printed 1) and Bliss (108, printed 2), dealt Bliss-first on purpose --
-- which card goes first is decided by ranking, never by deal order.
-- Goal: card_in_play Bliss, max_plays 3.
--
-- Correct (and, per an exhaustive search of every legal play order/choice
-- against the real engine, the ONLY) line:
--   1. Play Duplicity -- its own extra play is generic, so it pays for
--      Intimidation; in play, it also offers to repeat the next
--      after-playing effect.
--   2. Play Intimidation, targeting the opponent, and accept Duplicity's
--      repeat (targeting the opponent again). Reveal #1 is Charity (their
--      worst); reveal #2 is Bliss. Each steal grants a play restricted to
--      exactly that card.
--   3. Play Bliss using its own grant, discarding Charity to pay Bliss's
--      "discard a card from your hand" cost. Bliss is in play -- solved.
--
-- Three separate traps, each of which fails the puzzle:
--   * Intimidation first: it's a natural opener (it's the star), but then
--     Duplicity isn't in play to repeat it, and a lone Intimidation only
--     ever yields Charity.
--   * Duplicity first but declining the repeat: same result, Charity only.
--   * Taking both cards but playing Charity first with its free grant:
--     Charity's own extra play looks like a bonus, but it leaves Bliss with
--     nothing in hand to discard -- Bliss becomes illegal, and the play
--     budget is spent. The junk card you're handed is Bliss's payment, not
--     a card to play.
--
-- Deliberately a two-card hand: adding Creativity (copy Intimidation or
-- Duplicity) was tried and produced dozens of alternate solutions, since a
-- Creativity copy of Duplicity hands out extra plays faster than a hard
-- puzzle can tolerate.
INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'shakedown',
    'Shakedown',
    'Your hand: Duplicity and Intimidation. Your opponent holds Charity and Bliss in hand, and always reveals their lowest-value card. Get Bliss into play.',
    'Intimidation only ever takes the card your opponent values least -- and a card you''re handed is yours to keep instead of playing.',
    'hard',
    '[37, 67]', '[]', '[]', '[]',
    '[108, 3]', '[]',
    'card_in_play', '{"catalog_card_id": 108}', 3, NULL, 1
);

UPDATE schema_version SET version = '1.58.4' WHERE id = 1;

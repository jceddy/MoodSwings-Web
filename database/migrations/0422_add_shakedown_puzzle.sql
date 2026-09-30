-- Issue #524 follow-up ("I want to add another puzzle, centered around the
-- card Intimidation"): a 12th puzzle, "Shakedown."
--
-- New puzzle infrastructure this puzzle needed:
--   * goal_type 'win_game' ("win the game this turn"): solved the moment the
--     solver would win the round (the real RoundScorer math, ties to
--     whoever played first -- same as 'outscore_opponent') AND that round
--     win would clinch the game, i.e. banked wins + the round's own award
--     reach games.wins_needed. See GameService::puzzleGameWinningRoundWins().
--   * puzzles.solver_round_wins / opponent_round_wins: round wins are
--     always derived from 'scored' game_rounds rows, never a counter, so
--     createPuzzleAttempt() seeds that many already-scored rounds and the
--     live round takes the next round_number. Both default to 0, so every
--     existing puzzle is untouched.
ALTER TABLE puzzles
    ADD COLUMN solver_round_wins TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER opponent_in_play_card_ids,
    ADD COLUMN opponent_round_wins TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER solver_round_wins,
    MODIFY COLUMN goal_type ENUM('hand_empty', 'card_in_hand', 'card_in_play', 'min_score', 'outscore_opponent', 'win_game') NOT NULL;

-- The premise that makes Intimidation puzzle-worthy: the TARGET picks which
-- card to reveal, and the puzzle opponent (like every bot) always gives up
-- its WORST card first -- lowest draft_priority_score, then lowest printed
-- value (BotChoiceResolver::ownResourceCandidateValue()). So a single
-- Intimidation only ever takes the junk; the card you actually want has to
-- come second.
--
-- Setup: each player has two round wins (games.wins_needed is 3), so
-- winning this round wins the game; the opponent went first, so a tied
-- score goes to them. Hand: Duplicity (37), Intimidation (67). Opponent in
-- play: Smugness (134) and Unconcern (135), two ability-less 1-point rows
-- (total 2). Opponent's hand: Charity (3, printed 1) and Bliss (108,
-- printed 2), dealt Bliss-first on purpose -- which card goes first is
-- decided by ranking, never by deal order. max_plays 3.
--
-- Why TWO one-point moods rather than one: against a single 1-point mood,
-- an exhaustive search of every legal line found 14 that win the game,
-- 12 of them never touching Bliss (Intimidation then Charity alone scores
-- 2 against 1). Against a total of 2 the cheap lines only tie -- and a tie
-- goes to the opponent -- so exactly one line wins.
--
-- Correct (and, per that same exhaustive search, the ONLY) line:
--   1. Play Duplicity -- its own extra play is generic, so it pays for
--      Intimidation; in play, it also offers to repeat the next
--      after-playing effect.
--   2. Play Intimidation, targeting the opponent, and accept Duplicity's
--      repeat (targeting the opponent again). Reveal #1 is Charity (their
--      worst); reveal #2 is Bliss. Each steal grants a play restricted to
--      exactly that card.
--   3. Play Bliss using its own grant, discarding Charity to pay Bliss's
--      "discard a card from your hand" cost. Board: Duplicity 0 +
--      Intimidation 1 + Bliss 2 = 3 against the opponent's 2 -- the round,
--      and with it the game, is won.
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
    solver_round_wins, opponent_round_wins,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'shakedown',
    'Shakedown',
    'Each player has two round wins, and your opponent played first. Your hand: Duplicity and Intimidation. Your opponent has Smugness and Unconcern (1 point each) in play and holds Charity and Bliss in hand, and always reveals their lowest-value card. Win the game this turn.',
    'Intimidation only ever takes the card your opponent values least -- and a card you''re handed is yours to keep instead of playing.',
    'hard',
    '[37, 67]', '[]', '[]', '[]',
    '[108, 3]', '[134, 135]',
    2, 2,
    'win_game', '{}', 3, NULL, 1
);

UPDATE schema_version SET version = '1.58.4' WHERE id = 1;

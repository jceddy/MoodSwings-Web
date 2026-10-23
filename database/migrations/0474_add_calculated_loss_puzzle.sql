-- 30th puzzle, "Calculated Loss" (an Insecurity / Ambition / Patience puzzle: "You
-- have two round wins and your opponent has one. Your opponent played first this
-- round: they have Glee (which they played this round), Duplicity, Bashfulness,
-- Boredom and Apathy in play, and hold Intimidation. You have Celebration and
-- Apathy in play and hold Insecurity, Ambition, Patience and Triumph. Your deck
-- is empty. Win the game."), with new
-- puzzle infrastructure so that a puzzle can play out over several real rounds:
--   * puzzles.opponent_takes_turns (0/1, default 0): the opponent takes its own
--     turns, as an ordinary bot (so it plays the cards it holds, Intimidation
--     here), and rounds are scored by the normal pipeline. A lost round no longer
--     ends the attempt: it carries on into the next, and the attempt is a solve
--     if the solver wins the round that completes the game, a loss if the
--     opponent does. See GameService::advanceTurn() and finishPuzzleFromScoring();
--     checkPuzzleGoal() leaves such a puzzle to the scoring, and the plays
--     counter only counts the solver's own plays.
--
-- Setup: you have two round wins and the opponent one (games.wins_needed is 3), so
-- the opponent winning this round puts them one short, and whoever wins the round
-- after that takes the game. This round is unwinnable for you -- Glee reads 6
-- while it was played this round -- so the round after it is what counts. The
-- opponent went first and has already taken its turn this round, so it still
-- holds Intimidation: it plays it first thing next round, and a tie there goes to
-- it. Your deck is empty, so a lost round draws you nothing.
--   Moods that change value at the round boundary: Patience (white 5, but 1 if
--   you played it this round) and Glee (red 0, but 6 if you played it this round).
--   Insecurity (blue 2): you may play an additional mood; if you do, after scoring
--   put that mood into your hand if it is still in play. Ambition (black 2): you
--   may discard a card from your hand; if you do, you may play an additional mood.
--   Intimidation (black 1): the other player reveals a card from their hand and
--   gives it to you; you may play it as an additional mood. Duplicity (blue 0):
--   each time its owner plays another mood, that mood's after-playing effect can
--   happen an additional time -- so Intimidation takes TWO cards. Celebration
--   (green 3): 7 if you have more colors among your moods than each other player
--   does. Triumph (red 3): 5 if you went first this round -- so 5 for them next
--   round and always 3 for you.
--
-- The one winning line (exhaustive search through the real GameService flow):
--   This round: Insecurity, then Ambition (the mood Insecurity let you play),
--   discarding TRIUMPH to play one more, then Patience. You lose the round, but
--   Patience is now a mood you played LAST round, so it reads 5 from here on, and
--   Insecurity puts Ambition back in your hand. Your own Celebration reads 7: you
--   have green (Celebration), black (Apathy), blue (Insecurity) and white
--   (Patience) -- four colors against their three.
--   Next round: the opponent plays Intimidation, twice. Ambition is the only card
--   you hold, so it is the one you have to give up, and there is nothing left for
--   the second trigger to take. You have nothing left to play, so your turn just
--   passes: Celebration 7 + Apathy 4 + Insecurity 2 + Patience 5 = 18 against
--   Bashfulness 6 + Boredom 4 + Apathy 4 + Intimidation 1 + Ambition 2 = 17. A
--   win by one.
-- Why nothing else works: holding more cards means Intimidation takes two of them
-- (Duplicity), so playing Patience alone this round and the rest next round gives
-- away 2 + 5 or more. Skipping the discard means Patience is never played this
-- round, and it is worth 1 again next round. Playing Ambition first and giving up
-- Insecurity instead leaves you with black twice -- three colors, so Celebration
-- reads 3 (14-17). The closest misses: Patience alone, then giving up Insecurity
-- and Ambition and playing Triumph, ties 19-19 (they go first, so a tie is theirs);
-- discarding Patience instead of Triumph and playing Triumph loses 16-17.
ALTER TABLE puzzles
    ADD COLUMN opponent_takes_turns TINYINT(1) NOT NULL DEFAULT 0 AFTER starting_skip_scoring_first_player;

INSERT INTO puzzles (
    slug, title, description, hint, difficulty,
    starting_hand_card_ids, starting_in_play_card_ids, deck_card_ids, starting_discard_card_ids,
    opponent_hand_card_ids, opponent_in_play_card_ids,
    solver_round_wins, opponent_round_wins, solver_goes_first, prelude_log, starting_effect_state,
    opponent_takes_turns,
    goal_type, goal_params, max_plays, extra_play_source_card_id, active
) VALUES (
    'calculated-loss',
    'Calculated Loss',
    'You have two round wins and your opponent has one. Your opponent played first this round: they have Glee (which they played this round), Duplicity, Bashfulness, Boredom and Apathy in play, and hold Intimidation. You have Celebration and Apathy in play and hold Insecurity, Ambition, Patience and Triumph. Your deck is empty. Win the game.',
    'You can''t win this round, but what you play now -- and what you are still holding afterwards -- decides the next.',
    'hard',
    '[45, 53, 21, 104]', '[109, 55]', '[]', '[2, 6, 10, 14, 17, 20, 25, 29, 33, 37, 41, 46, 52, 56, 60, 64]',
    '[67]', '[92, 37, 30, 83, 55]',
    2, 1, 0, NULL, '{"92":{"playedInRound":"current","playedByPlayerId":"opponent"}}',
    1,
    'win_game', '{}', NULL, NULL, 1
);

UPDATE schema_version SET version = '1.62.23' WHERE id = 1;

-- Issue #524 follow-up (reported live: "For Turn It On Yourself -- There
-- is a Joy in the discard pile. You have Conviction in your hand, and one
-- extra play from Joy... Win the game in one turn."). Two new, general
-- puzzle-setup primitives, alongside the opponent-board one migration
-- 0395 added:
--
-- starting_discard_card_ids: seeds the shared discard pile itself (owned
-- by the solver) at attempt creation -- '[]' for every existing puzzle,
-- same "empty means unused" convention opponent_hand_card_ids/
-- opponent_in_play_card_ids already established.
--
-- extra_play_source_card_id: for a puzzle whose intended solution needs
-- an extra play already banked at turn start (e.g. "you have... one
-- extra play from Joy") rather than earned live during the attempt --
-- GameService::createPuzzleAttempt() resolves this catalog card id to
-- wherever its own instance actually got dealt (starting_discard_card_ids,
-- in this puzzle's case) and adds a second play grant sourced from it, in
-- exactly the shape a real banked Joy/Generosity play already takes.
-- NULL (no extra play) for every existing puzzle.
ALTER TABLE puzzles
    ADD COLUMN starting_discard_card_ids JSON NOT NULL AFTER deck_card_ids,
    ADD COLUMN extra_play_source_card_id SMALLINT UNSIGNED NULL AFTER max_plays;

UPDATE puzzles SET starting_discard_card_ids = '[]';

-- Turn It On Yourself, redesigned to need an opponent (same
-- outscore_opponent goal_type migration 0395 introduced for One Fell
-- Swoop): the solver's hand is just Conviction, with one extra play
-- already banked from an earlier Joy (now sitting in the discard pile).
-- The opponent has Benevolence(2) + Shock(2) in play (score 4) and went
-- first this round. Conviction's own "choose a mood, its player bottoms
-- it and draws a card" is a legal target on ANY mood in play, including
-- the opponent's -- but only targeting Conviction itself draws the
-- solver their own next card. That next card is Chivalry, seeded on top
-- of the deck (deck_card_ids[0]) -- worth 5 while in play (its own "value
-- is 5 if you didn't go first this round" -- the solver didn't). Playing
-- Chivalry with the banked extra play puts the solver at exactly 5,
-- outscoring the opponent's 4. Targeting Benevolence or Shock instead
-- bottoms an OPPONENT mood (so the OPPONENT draws, not the solver) and
-- leaves the solver's own Conviction(2) in play against whichever
-- opponent mood is left(2) -- a tie, which goes to the opponent (they
-- went first) rather than the solver. See
-- php-app/tests/Rules/PuzzleContentTest.php for all three lines
-- engine-verified.
--
-- The full 45-card decklist (starting_hand_card_ids + starting_discard_card_ids
-- + opponent_in_play_card_ids + deck_card_ids = 1 + 1 + 2 + 41 = 45) is a
-- real singleton structure-deck rarity mix (23 common/14 uncommon/6
-- rare/2 mythic -- GameService::STRUCTURE_DECK_RARITY_COUNTS) so a player
-- who checks the deck list (the "Card Counter" full-decklist view) can
-- see Chivalry itself is one of the 45 cards in this game -- a visible
-- out, even without knowing it's specifically the very next draw.
UPDATE puzzles SET
    title = 'Turn It On Yourself',
    description = 'You have Conviction in hand and one extra play already banked from Joy, which is in the discard pile. Your opponent has Benevolence and Shock in play, went first this round, and you''re both one win from taking the match. Win the game in a single turn.',
    difficulty = 'hard',
    starting_hand_card_ids = '[6]',
    starting_in_play_card_ids = '[]',
    starting_discard_card_ids = '[125]',
    deck_card_ids = '[4, 104, 119, 84, 66, 78, 30, 87, 62, 109, 131, 81, 17, 95, 55, 42, 115, 88, 28, 31, 35, 86, 45, 32, 19, 108, 71, 90, 48, 110, 14, 75, 54, 27, 40, 9, 63, 82, 8, 100, 64]',
    opponent_hand_card_ids = '[]',
    opponent_in_play_card_ids = '[2, 101]',
    goal_type = 'outscore_opponent',
    goal_params = '{}',
    max_plays = 2,
    extra_play_source_card_id = 125
WHERE slug = 'turn-it-on-yourself';

UPDATE schema_version SET version = '1.56.3' WHERE id = 1;

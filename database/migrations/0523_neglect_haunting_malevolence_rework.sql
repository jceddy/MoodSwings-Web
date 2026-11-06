-- Community set 'Emotional Baggage' (XEMB) rework of three cards:
--   Neglect: worth 2 (was 0); triggers "before scoring" (was the start of each of its owner's turns) and makes the opponent
--     put one of their HIGHEST VALUE moods (was any mood) into the discard pile.
--   Haunting: worth 0 (was 2); the extra plays from the discard pile on your next turn now need Haunting still in play at the
--     start of that turn.
--   Malevolence: reworded only.
-- game_rounds.before_scoring_done remembers which "before scoring" triggers have already fired this round, so a round paused on one's
-- decision doesn't fire it again when scoring resumes.
ALTER TABLE game_rounds ADD COLUMN before_scoring_done LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL CHECK (json_valid(before_scoring_done));

UPDATE cards
SET base_value = 2,
    rules_text = 'While in play — Before scoring, each opponent with no cards in their hand puts one of their highest value moods into the discard pile.'
WHERE id = 208 AND name = 'Neglect';

UPDATE cards
SET base_value = 0,
    rules_text = 'After playing this mood — You may discard any number of cards from your hand. If you do, on your next turn you may play that many additional moods from the discard pile if you still have this mood at the start of that turn.'
WHERE id = 202 AND name = 'Haunting';

UPDATE cards
SET rules_text = 'After playing this mood — Choose odd or even. Choose up to two players. Each chosen player puts one of their moods with a number of the chosen type in its top right corner into the discard pile. (0 is even.)'
WHERE id = 206 AND name = 'Malevolence';

UPDATE schema_version SET version = '1.64.27' WHERE id = 1;

-- Schadenfreude is now a "while in play" 3->6 that checks the whole round and counts only cards an OPPONENT
-- discarded from their hand (it was an after-playing check of the turn). The record it reads is round-scoped:
-- game_rounds.turn_discards (migration 0505, turn-scoped, hand discards and moods) becomes round_hand_discards.
ALTER TABLE game_rounds CHANGE COLUMN turn_discards round_hand_discards LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL;
UPDATE game_rounds SET round_hand_discards = NULL;

UPDATE cards
SET rules_text = 'While in play — This mood''s value is 6 if an opponent discarded a card this round.',
    has_while_in_play_ability = 1,
    has_after_playing_ability = 0
WHERE effect_key = 'schadenfreude';

UPDATE schema_version SET version = '1.64.11' WHERE id = 1;

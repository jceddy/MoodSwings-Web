-- Vitality's round-long color bonuses (BoardState::addRoundColorBonus()): kept on the live round as a JSON
-- list of {playerId, color, amount}, so they outlive the mood that created them and reach moods played later.
ALTER TABLE game_rounds ADD COLUMN round_value_bonuses LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL CHECK (json_valid(round_value_bonuses));

UPDATE schema_version SET version = '1.64.5' WHERE id = 1;

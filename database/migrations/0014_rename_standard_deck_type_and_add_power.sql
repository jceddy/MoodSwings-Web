-- deck_type's 'standard' value is renamed to 'structure' (a clearer name
-- now that a second small-deck option, 'power', exists alongside it), and
-- 'power' is added: a single random Mythic plus 14 other random non-Mythic
-- cards (15 total) -- a small, mythic-guaranteed deck for a faster,
-- higher-power game, distinct from 'structure''s own printed rarity mix
-- (23 common/14 uncommon/6 rare/2 mythic, 45 total) -- see
-- GameService::buildStructureDeckCardIds()/buildPowerDeckCardIds().
--
-- Enum values can't be renamed in place while rows still reference the old
-- one, so this widens the enum first, migrates existing rows, then narrows
-- it to the final set with the new default.
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('standard', 'structure', 'power', 'one_of_each') NOT NULL DEFAULT 'standard';

UPDATE games SET deck_type = 'structure' WHERE deck_type = 'standard';

ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'one_of_each') NOT NULL DEFAULT 'structure';

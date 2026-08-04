-- Adds 'saved_deck' as a sixth selectable pool source for Quick Draft/
-- Winston Draft/Grid Draft (issue #290): lets a player point a draft's
-- shared pool at one of their own saved decklists (issue #92's
-- UserDecklistService), reusing UserDecklistService::cardIdsForUse() the
-- same way the existing 'custom' deck_type already does for a whole
-- game's own deck -- see GameService::buildDraftPool()'s new 'saved_deck'
-- match arm.
ALTER TABLE draft_matches
    MODIFY COLUMN pool_source ENUM('random_48', 'structure', 'jceddys_75', 'one_of_each', 'custom', 'saved_deck') NOT NULL;

UPDATE schema_version SET version = '1.10.7' WHERE id = 1;

-- Adds jceddy's 75 Card deck (already an existing deck_type/custom_duel
-- rules preset -- see migration 0016/GameService::buildJceddys75DeckCardIds())
-- as a fifth selectable Quick Draft pool source, reusing that same
-- builder as-is. Its 75 cards get randomly narrowed down to 48 before
-- the draft begins, exactly like 'one_of_each's own 133 already do (see
-- GameService::buildQuickDraftPool()).
ALTER TABLE draft_matches
    MODIFY COLUMN pool_source ENUM('random_48', 'structure', 'jceddys_75', 'one_of_each', 'custom') NOT NULL;

UPDATE schema_version SET version = '0.6.3' WHERE id = 1;

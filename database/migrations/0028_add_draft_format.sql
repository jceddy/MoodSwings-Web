-- Adds a fifth `format`, 'draft': functionally identical to 'duel' (same
-- 2-player, separate-per-player-deck rules engine -- see
-- GameService::isDuelShapedFormat() and BoardStateRepository::load()'s own
-- $hasSeparateDecks check), but scoped to deck_type values that build a
-- player's deck through some kind of live drafting process rather than an
-- already-built pool/decklist. `quick_draft` (issue #88, previously gated
-- on `format = 'duel'`) is the first such deck_type and, for now, the only
-- one 'draft' games support -- see GameService::createGame()'s own
-- format<->deck_type validation. More draft-style deck types are expected
-- to join 'draft' later; none of them are expected to ever make sense
-- under 'duel' itself, hence the split into its own format rather than
-- reusing 'duel' with a wider set of allowed deck types.
ALTER TABLE games MODIFY COLUMN format ENUM('standard', 'duel', 'team', 'closed_team', 'draft') NOT NULL DEFAULT 'standard';

UPDATE schema_version SET version = '0.6.0' WHERE id = 1;

-- Sealed Deck (issue #392): a ninth "draft-family" deck_type, 'sealed_deck',
-- for the 'draft' format (and Team Play/Closed Team Play, same as every
-- other draft-based deck_type -- see GameService::createGame()'s own
-- format-validation docblock). Unlike Quick/Winston/Grid/Rotisserie/Tiered
-- Rotisserie Draft, there's no live picking/passing phase at all: each
-- seated player is independently dealt their own randomized 45-card pool
-- (structure-deck-sized -- see STRUCTURE_DECK_RARITY_COUNTS) up front, and
-- goes straight to building a minimum-12-card deck from it. This reuses
-- draft_matches/draft_match_players entirely unchanged (their own columns
-- are already deck-type-agnostic -- see migration 0027's own docblock) --
-- games.deck_type is what distinguishes the variant a match/game belongs
-- to, same as every prior deck_type migration. See php-app/README.md's
-- "Sealed Deck" section for the full mechanic.
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'custom', 'custom_duel', 'quick_draft', 'one_of_each', 'winston_draft', 'grid_draft', 'rotisserie_draft', 'tiered_rotisserie_draft', 'chaos_draft', 'sealed_deck') NOT NULL DEFAULT 'structure';

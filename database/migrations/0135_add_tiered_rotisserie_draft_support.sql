-- Tiered Rotisserie Draft (issue #361): an eleventh deck_type,
-- 'tiered_rotisserie_draft', for the 'draft' format alongside
-- 'quick_draft'/'winston_draft'/'grid_draft'/'rotisserie_draft'.
-- Generalizes base Rotisserie Draft's own single-pool mechanic into N
-- rounds ("tiers"), each drafting from its own distinct sub-pool with
-- its own per-player pick count, before the next tier begins -- pick
-- order snakes CONTINUOUSLY across ALL tiers (does not reset to seat 0
-- at the start of each new tier), reusing
-- GameService::rotisserieDraftPickUserId() completely unchanged, fed an
-- ever-incrementing GLOBAL pick_index that never resets at a tier
-- boundary (the same function already proved it handles an odd/uneven
-- final stretch gracefully -- base Rotisserie Draft's own
-- cutoff-count-need-not-be-even case is no different in kind from a
-- tier boundary landing mid-double-round).
--
-- Two selectable tiering modes (see "Tiered Rotisserie Draft" in
-- php-app/README.md for the full mechanic):
--   - 'rarity': the fixed reference scheme -- 4 tiers, one per rarity in
--     order Mythic/Rare/Uncommon/Common, each tier's own layout size
--     exactly double what it actually distributes (picks of 1/2/4/8 per
--     player, laid out at 2/4/8/16 times player count).
--   - 'custom': the creator configures 2-4 tiers themselves, each with
--     its own pool (a saved decklist or pasted custom list) and its own
--     pick cutoff count, the cutoffs summing to at least 12 (the same
--     fixed minimum deck-build size both modes share, see
--     GameService::ROTISSERIE_DRAFT_MIN_DECK_SIZE).
--
-- tiers is the whole match's own per-tier state in one JSON array,
-- ordered the same as they're drafted -- each element
-- {label, cutoff_count, pool_card_ids}: label names which rarity a
-- 'rarity'-mode tier is (null for 'custom' mode, where tiers have no
-- inherent name), cutoff_count is that tier's own fixed pick-per-player
-- target, and pool_card_ids holds every card STILL available to pick in
-- that tier specifically -- exactly like draft_rotisserie_state's own
-- pool_card_ids, just one shrinking array per tier instead of one for
-- the whole match, and (unlike that single-tier table) resolved and
-- stored for every configured tier up front at creation time, not
-- built/dealt lazily as each tier is reached.
--
-- starter_seat_offset/pick_index/current_turn_user_id mirror
-- draft_rotisserie_state's own columns exactly (see that table's own
-- migration 0125 comment and migration 0134's starter_seat_offset
-- follow-up) -- pick_index is the SAME global, never-reset-per-tier
-- counter rotisserieDraftPickUserId() itself consumes; which tier is
-- currently active is deliberately NOT its own stored column, but
-- derived on demand from pick_index and each tier's own cutoff_count
-- (cumulative picks-per-tier boundaries) -- one less piece of mutable
-- state that could drift out of sync with pick_index itself.
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'custom', 'custom_duel', 'quick_draft', 'one_of_each', 'winston_draft', 'grid_draft', 'rotisserie_draft', 'tiered_rotisserie_draft') NOT NULL DEFAULT 'structure';

-- draft_matches.pool_source also records createGame()'s own
-- $draftPoolSource for a Tiered Rotisserie Draft match, informational
-- only (same "audit trail, not read back" role it already plays for
-- every other draft deck_type -- see this table's own migration 0125
-- comment) -- 'custom' mode's own tiers can each independently be
-- 'custom'/'saved_deck' pool-sourced (already valid values here), but
-- the match-level value createGame() records is the *tiering mode*
-- itself (GameService::buildTieredRotisserieDraftTierPools()'s own
-- $mode), so 'rarity' needs adding alongside the pool sources already
-- here.
ALTER TABLE draft_matches
    MODIFY COLUMN pool_source ENUM('random_48', 'structure', 'jceddys_75', 'one_of_each', 'custom', 'saved_deck', 'rarity') NOT NULL;

CREATE TABLE IF NOT EXISTS draft_tiered_rotisserie_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    draft_match_id BIGINT UNSIGNED NOT NULL,
    tiering_mode ENUM('rarity', 'custom') NOT NULL,
    tiers JSON NOT NULL,
    starter_seat_offset TINYINT UNSIGNED NOT NULL DEFAULT 0,
    pick_index INT UNSIGNED NOT NULL DEFAULT 0,
    current_turn_user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_draft_tiered_rotisserie_state_match (draft_match_id),
    CONSTRAINT fk_draft_tiered_rotisserie_state_match FOREIGN KEY (draft_match_id) REFERENCES draft_matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_tiered_rotisserie_state_current_turn FOREIGN KEY (current_turn_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.26.0' WHERE id = 1;

-- Rotisserie Draft (issue #361): a tenth deck_type, 'rotisserie_draft',
-- for the 'draft' format alongside 'quick_draft'/'winston_draft'/
-- 'grid_draft'. Unlike those three, there's no packs/piles/grid at all --
-- the entire pool is dealt face-up once, up front, and players simply
-- pick one card at a time in a fixed snake-style turn order (see
-- GameService::rotisserieDraftPickUserId()) until each has picked a
-- creator-chosen cutoff count (13-20, default 14). Reuses
-- draft_matches/draft_match_players as-is (pool_source/pool_card_ids/
-- status/wins/drafted_card_ids/deck_card_ids/previous_deck_card_ids/
-- winner_user_id are already deck-type-agnostic), same as every other
-- draft deck_type -- games.deck_type is what distinguishes which variant
-- a match/game belongs to. See php-app/README.md's "Rotisserie Draft"
-- section for the full mechanic.
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'custom', 'custom_duel', 'quick_draft', 'one_of_each', 'winston_draft', 'grid_draft', 'rotisserie_draft') NOT NULL DEFAULT 'structure';

-- One row per Rotisserie Draft match (not per game), holding the mutable
-- pool/turn-pointer state only this mechanic needs. Like Winston/Grid
-- Draft (and unlike Quick Draft's simultaneous-blind own
-- draft_round_picks), Rotisserie Draft has no simultaneity -- turns
-- strictly rotate between exactly one active player at a time -- so a
-- straightforward mutable row, protected by the same per-game
-- withGameLock() every draft mutation already uses, is both simpler and
-- just as safe here.
--
-- pool_card_ids holds every card still available to pick, face-up --
-- unlike Grid Draft's own remaining_deck_card_ids (a hidden reserve
-- dealt out round by round), the WHOLE built pool is visible from the
-- very start; a picked card is simply removed from this array, and
-- nothing is ever dealt/refilled/reshuffled back in. The pool may be
-- (and, for every pool source except the built-in random one, usually
-- is) larger than cutoff_count * (number of players) -- whatever's left
-- once every player has reached the cutoff is simply left undrafted, the
-- same "remainder discarded" precedent Rarity Rotisserie's own rules
-- already establish (see issue #361).
--
-- cutoff_count is this match's own chosen cutoff (13-20, GameService::
-- ROTISSERIE_DRAFT_MIN_CUTOFF/MAX_CUTOFF), fixed for the whole match --
-- the same shape as a per-game setting fixed for a game's whole
-- lifetime (see e.g. default_selections_mode), just scoped to the match
-- instead since a draft match's own games all share one draft.
--
-- pick_index is a simple 0-based counter of how many picks have
-- happened so far, THE single source of truth both for whose turn it is
-- (GameService::rotisserieDraftPickUserId($userIds, $pick_index)) and
-- for when the draft ends (pick_index reaching cutoff_count * player
-- count) -- current_turn_user_id is a derived cache of the former,
-- stored redundantly purely so a plain SELECT can answer "whose turn is
-- it" without recomputing the snake formula, the same convenience
-- draft_grid_state's own current_turn_user_id already provides.
CREATE TABLE IF NOT EXISTS draft_rotisserie_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    draft_match_id BIGINT UNSIGNED NOT NULL,
    pool_card_ids JSON NOT NULL,
    cutoff_count TINYINT UNSIGNED NOT NULL,
    pick_index INT UNSIGNED NOT NULL DEFAULT 0,
    current_turn_user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_draft_rotisserie_state_match (draft_match_id),
    CONSTRAINT fk_draft_rotisserie_state_match FOREIGN KEY (draft_match_id) REFERENCES draft_matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_rotisserie_state_current_turn FOREIGN KEY (current_turn_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.25.0' WHERE id = 1;

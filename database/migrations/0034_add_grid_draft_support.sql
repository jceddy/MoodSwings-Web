-- Grid Draft (issue #188): a ninth deck_type, 'grid_draft', for the
-- 'draft' format alongside 'quick_draft'/'winston_draft' (migration 0028
-- split 'draft' out from 'duel' specifically so more live-drafting deck
-- types could join it later). Reuses draft_matches/draft_match_players
-- as-is (pool_source/pool_card_ids/status/wins/drafted_card_ids/
-- deck_card_ids/previous_deck_card_ids/winner_user_id are already
-- deck-type-agnostic) -- games.deck_type is what distinguishes which
-- variant a match/game belongs to. See php-app/README.md's "Grid Draft"
-- section for the full mechanic.
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'custom', 'custom_duel', 'quick_draft', 'one_of_each', 'winston_draft', 'grid_draft') NOT NULL DEFAULT 'structure';

-- One row per Grid Draft match (not per game), holding the mutable
-- 3x3-grid/deck/turn-pointer state only Grid Draft's own mechanic needs.
-- Like Winston Draft (and unlike Quick Draft's simultaneous-blind own
-- draft_round_picks), Grid Draft has no simultaneity -- turns strictly
-- alternate between exactly one active player -- so a straightforward
-- mutable row, protected by the same per-game withGameLock() every draft
-- mutation already uses, is both simpler and just as safe here.
--
-- remaining_deck_card_ids is the not-yet-dealt portion of the pool (9
-- cards get dealt off the front into a fresh grid_card_ids at the start
-- of every round; unlike Winston Draft, nothing ever gets reshuffled
-- back in, and 54 cards over 6 rounds of 9 divides evenly, so this
-- reaches exactly empty the moment the 6th and final round is dealt).
-- grid_card_ids holds the current round's 9 cells in row-major order
-- (index = row * 3 + column); a cell becomes JSON null the instant it's
-- taken by either player, so the *second* pick of a round can derive
-- exactly how many cards it actually covers (2 if it crosses the first
-- pick's row/column, 3 if it doesn't) by simply counting how many of its
-- three cells are still non-null, rather than needing any separately
-- stored intersection flag.
--
-- first_picker_user_id is whoever goes first THIS round (alternates
-- every round -- round 1's is chosen at random, see
-- GameService::initializeGridDraft()); current_turn_user_id is whoever
-- actually acts next (the same as first_picker_user_id until they've
-- made their pick, then the other seated player for the round's second
-- pick, then back to the next round's own first_picker_user_id).
-- first_pick_axis/first_pick_index record the first pick's own row-or-
-- column choice for that reason -- both null exactly when it's still the
-- first pick of the round, non-null for the second.
CREATE TABLE IF NOT EXISTS draft_grid_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    draft_match_id BIGINT UNSIGNED NOT NULL,
    remaining_deck_card_ids JSON NOT NULL,
    current_round TINYINT UNSIGNED NOT NULL DEFAULT 1,
    grid_card_ids JSON NOT NULL,
    first_picker_user_id INT UNSIGNED NOT NULL,
    current_turn_user_id INT UNSIGNED NOT NULL,
    first_pick_axis ENUM('row', 'column') DEFAULT NULL,
    first_pick_index TINYINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_draft_grid_state_match (draft_match_id),
    CONSTRAINT fk_draft_grid_state_match FOREIGN KEY (draft_match_id) REFERENCES draft_matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_grid_state_first_picker FOREIGN KEY (first_picker_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_grid_state_current_turn FOREIGN KEY (current_turn_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.9.0' WHERE id = 1;

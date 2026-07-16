-- Winston Draft (issue #89): an eighth deck_type, 'winston_draft', for
-- the 'draft' format alongside 'quick_draft' (migration 0028 split
-- 'draft' out from 'duel' specifically so more live-drafting deck types
-- could join it later). Reuses draft_matches/draft_match_players as-is
-- (pool_source/pool_card_ids/status/wins/drafted_card_ids/deck_card_ids/
-- previous_deck_card_ids/winner_user_id are already deck-type-agnostic)
-- -- games.deck_type is what distinguishes which variant a match/game
-- belongs to. See php-app/README.md's "Winston Draft" section for the
-- full mechanic.
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'custom', 'custom_duel', 'quick_draft', 'one_of_each', 'winston_draft') NOT NULL DEFAULT 'structure';

-- One row per Winston Draft match (not per game), holding the mutable
-- pile/deck/turn-pointer state that only Winston Draft's own pile
-- mechanic needs -- Quick Draft has no equivalent, hence a separate
-- table rather than nullable columns on draft_matches that a Quick
-- Draft match would never use.
--
-- Unlike Quick Draft's own draft_round_picks (deliberately never storing
-- a mutable "remaining pool" blob -- see migration 0027's own docblock,
-- written specifically about *simultaneous blind* submissions needing
-- to independently derive state without racing each other), Winston
-- Draft has no simultaneity at all: turns strictly alternate between
-- exactly one active player at a time. A straightforward mutable row,
-- protected by the same per-game withGameLock() every draft mutation
-- already uses, is both simpler and just as safe here.
--
-- remaining_deck_card_ids is ordered (the front of the array is the top
-- of the deck); pile_1/2/3_card_ids' own internal order never matters,
-- since taking a pile always takes every card in it at once.
-- current_pile_number (1-3) is which pile is being looked at THIS turn
-- -- always starts back at 1 the moment a turn ends (a take, or a
-- decline-all-3-piles auto-draw). current_player_user_id is whose turn
-- it currently is.
CREATE TABLE IF NOT EXISTS draft_winston_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    draft_match_id BIGINT UNSIGNED NOT NULL,
    remaining_deck_card_ids JSON NOT NULL,
    pile_1_card_ids JSON NOT NULL,
    pile_2_card_ids JSON NOT NULL,
    pile_3_card_ids JSON NOT NULL,
    current_player_user_id INT UNSIGNED NOT NULL,
    current_pile_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_draft_winston_state_match (draft_match_id),
    CONSTRAINT fk_draft_winston_state_match FOREIGN KEY (draft_match_id) REFERENCES draft_matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_winston_state_player FOREIGN KEY (current_player_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.7.0' WHERE id = 1;

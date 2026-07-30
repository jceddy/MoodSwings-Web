-- Issue #189: 3-4 player Quick Draft. Winston Draft and Grid Draft stay
-- 2-player-only for now (their own multiplayer variants are still being
-- decided) -- this migration only touches Quick Draft's own pick-tracking
-- table.
--
-- The old draft_round_picks shape hardcoded exactly 2 pick "stages" per
-- round via 2 fixed column pairs (kept_from_draw_ids/submitted_draw_at,
-- kept_from_received_ids/submitted_received_at) -- correct only because a
-- 2-player pile (2N+2 = 6 cards) always takes exactly 2 takes to whittle
-- down to the 2 that get discarded. For N players, a pile takes exactly N
-- takes instead (one full lap of every seated player before stopping just
-- short of returning to its own owner) -- see GameService::quickDraftPileSize()/
-- submitQuickDraftPick()'s own docblock for the seat-rotation math. That
-- can't be represented as a fixed number of columns, so the "kept" data
-- moves to its own table, one row per (round, pile, stage) instead of one
-- row per (round, player) with 2 fixed sub-columns.
--
-- draft_round_picks itself is untouched except for dropping the 4 columns
-- that moved out -- it still records exactly what it always has, the
-- initial per-player-per-round deal (drawn_card_ids).
ALTER TABLE draft_round_picks
    DROP COLUMN kept_from_draw_ids,
    DROP COLUMN kept_from_received_ids,
    DROP COLUMN submitted_draw_at,
    DROP COLUMN submitted_received_at;

-- One row per pile per stage once that stage's pick is made.
-- pile_owner_user_id identifies WHICH of the round's N piles this is (the
-- user it was originally dealt to via draft_round_picks) -- it does NOT
-- change as the pile passes hands. holder_user_id is whoever actually held
-- (and picked from) the pile at this stage -- equal to pile_owner_user_id
-- only at stage_number = 1, some other seated player for every later
-- stage. Both are needed: pile_owner_user_id to look up what pile a player
-- currently holds (and to derive discards once every stage exists),
-- holder_user_id to credit the right player's drafted_card_ids in
-- GameService::finalizeQuickDraft(). Passed cards (= the pile's contents
-- minus every stage's own kept_card_ids so far) and discarded cards (= the
-- final 2 cards left once every stage_number 1..N exists for a pile) are
-- both derived at read time rather than stored, exactly like the table
-- this replaces.
CREATE TABLE IF NOT EXISTS draft_pile_stage_picks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    draft_match_id BIGINT UNSIGNED NOT NULL,
    round_number TINYINT UNSIGNED NOT NULL,
    pile_owner_user_id INT UNSIGNED NOT NULL,
    stage_number TINYINT UNSIGNED NOT NULL,
    holder_user_id INT UNSIGNED NOT NULL,
    kept_card_ids JSON NOT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_draft_pile_stage_picks_per_stage (draft_match_id, round_number, pile_owner_user_id, stage_number),
    CONSTRAINT fk_draft_pile_stage_picks_match FOREIGN KEY (draft_match_id) REFERENCES draft_matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_pile_stage_picks_owner FOREIGN KEY (pile_owner_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_pile_stage_picks_holder FOREIGN KEY (holder_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.7.0' WHERE id = 1;

-- ============================================================================
-- CONSOLIDATED migrations 0033 through 0036, for MANUAL use only.
-- ============================================================================
--
-- This is every statement from migrations/0033_add_game_resignation_support.sql
-- through migrations/0036_widen_winston_draft_last_action_tracking.sql,
-- concatenated in their original order (game resignation, Grid Draft --
-- issue #188 -- and Winston Draft's opponent-info follow-ups), for applying
-- via phpMyAdmin's SQL tab in one paste instead of running 4 separate files
-- by hand. It is NOT itself a migration and is deliberately kept out of
-- database/migrations/ -- bin/migrate.php only globs *.sql directly in that
-- directory, so this file living in database/consolidated/ instead means it
-- can never be picked up (or re-applied) by the normal migration runner.
--
-- Unlike consolidated/0001-0020_consolidated.sql, this is NOT meant for a
-- genuinely empty database -- only use it against one that already has
-- migrations 0001 through 0032 applied (i.e. schema_version currently
-- reports 0.7.0). Every CREATE TABLE below is IF NOT EXISTS and every ALTER
-- TABLE is a plain (non-idempotent) column/index/FK change exactly as it
-- shipped, so running this against a database missing an earlier migration,
-- or one that already has some (but not all) of 0033-0036 applied, will
-- fail partway through rather than skipping what's already there the way
-- `composer migrate` does.
--
-- Each original migration's own trailing `UPDATE schema_version` statement
-- is preserved in place rather than collapsed into one -- they're cheap,
-- idempotent overwrites, and keeping them exactly where they were means
-- this file's per-section content matches its source migration verbatim.
-- The net effect is still just one final version, 0.11.0, once every
-- statement below has run.
--
-- After running this, schema_migrations (already created by the 0001-0020
-- consolidated script, or by migrations/0021_add_schema_version.sql's own
-- predecessor if you applied 0001-0026 individually) is topped up with
-- these 4 filenames, so `composer migrate` correctly recognizes 0033-0036
-- as already applied and only ever applies 0037 onward from here.
--
-- Regenerate this file (by hand) if you want a future consolidated script
-- covering more of the history -- it is a point-in-time snapshot, not
-- automatically kept in sync with migrations/ as new ones are added.

-- ---------------------------------------------------------------------------
-- 0033_add_game_resignation_support.sql
-- ---------------------------------------------------------------------------
-- "Resign game" (lets a player give up instead of playing a game out).
--
-- resigned_at marks a seat as having resigned -- left NULL for every
-- player who hasn't. For 2-player games (duel/draft) and team-format
-- games (team/closed_team, always 2 opposing sides), a resignation
-- immediately completes the whole game in the remaining side's favor,
-- exactly like a normal round-based win -- no new round status is
-- needed for that case, since the round is simply abandoned mid-play.
-- The 'standard' format uniquely supports 3-4 players though, and for
-- that case resigning does NOT end the game: the resigning player is
-- marked out (their future turns skipped, and they're excluded from
-- ever winning a round or the game), while the rest of the table plays
-- on to a normal finish. Either way, the round a resignation happens
-- during needs to be taken out of 'in_progress' so GameService's own
-- currentRound() (which every play/pass already gates on) can't be used
-- to keep playing against a round whose game has already ended --
-- that's what the new 'abandoned' round status is for.
ALTER TABLE game_players
    ADD COLUMN resigned_at TIMESTAMP NULL DEFAULT NULL AFTER left_at;

ALTER TABLE game_rounds
    MODIFY COLUMN status ENUM('in_progress', 'scored', 'abandoned') NOT NULL DEFAULT 'in_progress';

UPDATE schema_version SET version = '0.8.0' WHERE id = 1;

-- ---------------------------------------------------------------------------
-- 0034_add_grid_draft_support.sql
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- 0035_add_winston_draft_last_take_tracking.sql
-- ---------------------------------------------------------------------------
-- Winston Draft: track each player's own most recent pile *take* (not
-- pass), so the opponent's last-drafted pile number can be shown without
-- ever revealing what was actually in it. Turns strictly alternate and
-- either player can pass any number of times before eventually taking,
-- so a single "the last take, whoever it was" column isn't enough --
-- from either player's own perspective, "the opponent's last take" means
-- that OTHER player's own most recent take specifically, which can be
-- several turns back. Keyed by user_id (JSON map, e.g. {"5": 2}) rather
-- than two fixed columns, since a draft match has no fixed "player
-- 1"/"player 2" slot the way a seat_order does elsewhere in this schema.
ALTER TABLE draft_winston_state
    ADD COLUMN last_take_pile_by_user_id JSON NULL AFTER current_pile_number;

UPDATE schema_version SET version = '0.10.0' WHERE id = 1;

-- ---------------------------------------------------------------------------
-- 0036_widen_winston_draft_last_action_tracking.sql
-- ---------------------------------------------------------------------------
-- Winston Draft: last_take_pile_by_user_id only recorded a *take*, so a
-- player who instead declined all 3 piles and took the mandatory
-- top-of-deck draw left the opponent's view showing a stale (possibly
-- many-turns-old) pile number instead of reflecting what actually just
-- happened. Widen the column to record either outcome per user_id: an
-- integer pile number (1-3) for a take, or the string "deck" for a
-- decline-all-piles auto-draw. Renamed to match the widened meaning.
ALTER TABLE draft_winston_state
    CHANGE COLUMN last_take_pile_by_user_id last_draft_action_by_user_id JSON NULL;

UPDATE schema_version SET version = '0.11.0' WHERE id = 1;

-- ---------------------------------------------------------------------------
-- Record migrations 0033-0036 as already-applied, so `composer migrate`
-- (see php-app/bin/migrate.php) skips straight to 0037+ from here rather
-- than trying to re-run any of the above.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (migration) VALUES
    ('0033_add_game_resignation_support.sql'),
    ('0034_add_grid_draft_support.sql'),
    ('0035_add_winston_draft_last_take_tracking.sql'),
    ('0036_widen_winston_draft_last_action_tracking.sql');

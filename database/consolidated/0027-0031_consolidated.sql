-- ============================================================================
-- CONSOLIDATED migrations 0027 through 0031, for MANUAL use only.
-- ============================================================================
--
-- This is every statement from migrations/0027_add_quick_draft_support.sql
-- through migrations/0031_add_jceddys_75_quick_draft_pool.sql, concatenated
-- in their original order (Quick Draft, issue #88, and its follow-up
-- fixes/polish), for applying via phpMyAdmin's SQL tab in one paste instead
-- of running 5 separate files by hand. It is NOT itself a migration and is
-- deliberately kept out of database/migrations/ -- bin/migrate.php only
-- globs *.sql directly in that directory, so this file living in
-- database/consolidated/ instead means it can never be picked up (or
-- re-applied) by the normal migration runner.
--
-- Unlike consolidated/0001-0020_consolidated.sql, this is NOT meant for a
-- genuinely empty database -- only use it against one that already has
-- migrations 0001 through 0026 applied (i.e. schema_version currently
-- reports 0.4.3). Every CREATE TABLE below is IF NOT EXISTS and every ALTER
-- TABLE is a plain (non-idempotent) column/index/FK change exactly as it
-- shipped, so running this against a database missing an earlier migration,
-- or one that already has some (but not all) of 0027-0031 applied, will
-- fail partway through rather than skipping what's already there the way
-- `composer migrate` does.
--
-- Each original migration's own trailing `UPDATE schema_version` statement
-- is preserved in place rather than collapsed into one -- they're cheap,
-- idempotent overwrites, and keeping them exactly where they were means
-- this file's per-section content matches its source migration verbatim.
-- The net effect is still just one final version, 0.6.3, once every
-- statement below has run.
--
-- After running this, schema_migrations (already created by the 0001-0020
-- consolidated script, or by migrations/0021_add_schema_version.sql's own
-- predecessor if you applied 0001-0026 individually) is topped up with
-- these 5 filenames, so `composer migrate` correctly recognizes 0027-0031
-- as already applied and only ever applies 0032 onward from here.
--
-- Regenerate this file (by hand) if you want a future consolidated script
-- covering more of the history -- it is a point-in-time snapshot, not
-- automatically kept in sync with migrations/ as new ones are added.

-- ---------------------------------------------------------------------------
-- 0027_add_quick_draft_support.sql
-- ---------------------------------------------------------------------------
-- Quick Draft (issue #88): a seventh deck_type, 'quick_draft', for Duel
-- games only. Instead of an already-built shared/per-player deck, the two
-- players draft their decks live from a shared card pool, then play a
-- best-of-three match (with sideboarding between games) using those
-- drafted decks -- see php-app/README.md's "Quick Draft" section for the
-- full mechanic.
--
-- Unlike every other deck_type, a Quick Draft match spans up to 3
-- separate `games` rows (one per best-of-three game) -- nothing like that
-- has existed until now, so the drafted-deck data can't live on `games`/
-- `game_players` the way custom_duel's per-seat decklist does (that data
-- is scoped to a single game row, but a Quick Draft player's drafted pool
-- and current deck must survive across all 3). draft_match_id/
-- match_game_number link each of those `games` rows back to one shared
-- draft_matches row and record which game (1/2/3) of the match it is.
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'custom', 'custom_duel', 'quick_draft', 'one_of_each') NOT NULL DEFAULT 'structure',
    ADD COLUMN draft_match_id BIGINT UNSIGNED DEFAULT NULL AFTER custom_duel_even_color_distribution_rarities,
    ADD COLUMN match_game_number TINYINT UNSIGNED DEFAULT NULL AFTER draft_match_id;

-- One row per Quick Draft match (not per game -- see above). pool_card_ids
-- is the up-to-48-card shared pool every round's draws come from, fixed
-- once at match creation (GameService::buildQuickDraftPool()) --
-- 'structure'/'custom' pools smaller than 48 are drafted from directly,
-- topping back up from that round's own already-discarded cards when the
-- remaining pool runs short (see GameService::dealQuickDraftRound()),
-- exactly mirroring the physical game's "reshuffle 3 discards back in"
-- workaround for a 45-card box.
--
-- status: 'drafting' (rounds 1-4 of the draft itself) -> 'deck_building'
-- (both the very first 16-to-14/15/16 trim AND every later sideboard
-- reuse this one status/endpoint -- there's no reason to distinguish
-- "first trim" from "a sideboard", they're the same operation) ->
-- 'completed' (one side has won 2 games). current_round only means
-- anything while status = 'drafting'.
CREATE TABLE IF NOT EXISTS draft_matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by_user_id INT UNSIGNED NOT NULL,
    pool_source ENUM('random_48', 'structure', 'one_of_each', 'custom') NOT NULL,
    custom_pool_name VARCHAR(120) DEFAULT NULL,
    pool_card_ids JSON NOT NULL,
    status ENUM('drafting', 'deck_building', 'completed') NOT NULL DEFAULT 'drafting',
    current_round TINYINT UNSIGNED NOT NULL DEFAULT 1,
    winner_user_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_draft_matches_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_matches_winner FOREIGN KEY (winner_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE games
    ADD CONSTRAINT fk_games_draft_match FOREIGN KEY (draft_match_id) REFERENCES draft_matches (id) ON DELETE SET NULL;

-- Keyed by (draft_match_id, user_id) rather than game_player_id, since
-- this data must outlive any single one of the match's up to 3 `games`
-- rows. drafted_card_ids is the fixed 16-card result of the draft itself
-- (null until round 4 completes); deck_card_ids is the player's *current*
-- 14-16 card selection out of those 16, written by
-- GameService::submitQuickDraftDeck() and explicitly nulled out every
-- time the next game in the match is created (see
-- GameService::finishScoringAndAdvance()) so a stale deck from the
-- previous game can never silently satisfy startGame()'s "deck
-- submitted" gate. wins is this match's own best-of-three counter,
-- unrelated to games.wins_needed/game_round_scores (those track a single
-- game's internal round wins).
CREATE TABLE IF NOT EXISTS draft_match_players (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    draft_match_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    drafted_card_ids JSON DEFAULT NULL,
    deck_card_ids JSON DEFAULT NULL,
    wins TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_draft_match_players_per_user (draft_match_id, user_id),
    CONSTRAINT fk_draft_match_players_match FOREIGN KEY (draft_match_id) REFERENCES draft_matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_match_players_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per (draft_match, user, round) -- at most 8 ever exist per
-- match (4 rounds x 2 players), cheap to scan in full for every derived
-- value. Only what's actually chosen is stored: drawn_card_ids (the 6
-- cards dealt this round), kept_from_draw_ids (this player's stage-A
-- keep-2-of-6), kept_from_received_ids (stage-B's keep-2-of-4-received).
-- Passed cards (= drawn minus kept_from_draw), received cards (= the
-- opponent's own passed cards this same round), and discarded cards (=
-- received minus kept_from_received) are all derived from these three
-- columns at read time rather than stored -- matches this codebase's
-- existing "recompute from source rows" approach (see
-- BoardStateRepository) and avoids a second, independently-mutable copy
-- of state that the stored columns above already fully determine.
CREATE TABLE IF NOT EXISTS draft_round_picks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    draft_match_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    round_number TINYINT UNSIGNED NOT NULL,
    drawn_card_ids JSON NOT NULL,
    kept_from_draw_ids JSON DEFAULT NULL,
    kept_from_received_ids JSON DEFAULT NULL,
    submitted_draw_at TIMESTAMP NULL DEFAULT NULL,
    submitted_received_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_draft_round_picks_per_round (draft_match_id, user_id, round_number),
    CONSTRAINT fk_draft_round_picks_match FOREIGN KEY (draft_match_id) REFERENCES draft_matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_round_picks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.5.0' WHERE id = 1;

-- ---------------------------------------------------------------------------
-- 0028_add_draft_format.sql
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- 0029_add_quick_draft_previous_deck.sql
-- ---------------------------------------------------------------------------
-- Quick Draft's per-game sideboard step (GameService::submitQuickDraftDeck())
-- always starts a new game's deck_card_ids as NULL (see
-- advanceQuickDraftMatch()) so startGame() can't silently treat an
-- unconfirmed leftover deck as already submitted. That left the frontend's
-- deck-trim picker with nothing better to default its checkboxes to than
-- "every drafted card", forcing the player to redo their whole trim from
-- scratch before every single game in the match instead of just adjusting
-- it. previous_deck_card_ids records whatever deck_card_ids was right
-- before it got nulled for the next game, purely so the frontend can
-- pre-select it as a starting point -- it plays no part in startGame()'s
-- own "has this game's deck been submitted yet" gate, which still keys
-- off deck_card_ids alone.
ALTER TABLE draft_match_players ADD COLUMN previous_deck_card_ids JSON DEFAULT NULL AFTER deck_card_ids;

UPDATE schema_version SET version = '0.6.1' WHERE id = 1;

-- ---------------------------------------------------------------------------
-- 0030_fix_rationalization_optional.sql
-- ---------------------------------------------------------------------------
-- Rationalization's printed text is "After playing this mood, you may
-- choose one: ..." -- the 0003 catalog seed dropped "you may", which made
-- the effect look mandatory and RationalizationEffect was implemented to
-- match that (wrongly) mandatory reading, forcing a mode choice on every
-- play instead of letting the player decline. This corrects the stored
-- text to match the printed card; RationalizationEffect itself is fixed
-- in the same change to actually treat 'mode' as optional.
UPDATE cards SET rules_text = 'After playing this mood, you may choose one: put your hand on the bottom of the deck then draw that many cards, or choose left or right and have each player simultaneously give their hand to the next player in that direction.' WHERE id = 49;

UPDATE schema_version SET version = '0.6.2' WHERE id = 1;

-- ---------------------------------------------------------------------------
-- 0031_add_jceddys_75_quick_draft_pool.sql
-- ---------------------------------------------------------------------------
-- Adds jceddy's 75 Card deck (already an existing deck_type/custom_duel
-- rules preset -- see migration 0016/GameService::buildJceddys75DeckCardIds())
-- as a fifth selectable Quick Draft pool source, reusing that same
-- builder as-is. Its 75 cards get randomly narrowed down to 48 before
-- the draft begins, exactly like 'one_of_each's own 133 already do (see
-- GameService::buildQuickDraftPool()).
ALTER TABLE draft_matches
    MODIFY COLUMN pool_source ENUM('random_48', 'structure', 'jceddys_75', 'one_of_each', 'custom') NOT NULL;

UPDATE schema_version SET version = '0.6.3' WHERE id = 1;

-- ---------------------------------------------------------------------------
-- Record migrations 0027-0031 as already-applied, so `composer migrate`
-- (see php-app/bin/migrate.php) skips straight to 0032+ from here rather
-- than trying to re-run any of the above.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (migration) VALUES
    ('0027_add_quick_draft_support.sql'),
    ('0028_add_draft_format.sql'),
    ('0029_add_quick_draft_previous_deck.sql'),
    ('0030_fix_rationalization_optional.sql'),
    ('0031_add_jceddys_75_quick_draft_pool.sql');

-- Issue #520: "Implement 'Sealed Pool of the Day' and 'Weekly Sealed
-- Pool' modes" -- this migration covers the first half, Sealed Pool of
-- the Day (Weekly Sealed Pool's own queue/leaderboard tables land in a
-- later migration).
--
-- A tenth "draft-family" deck_type, 'sealed_pool_of_the_day': unlike
-- ordinary Sealed Deck (issue #392, migration 0211), where each seated
-- player is independently dealt their OWN randomized pool, every game
-- created with this deck_type on a given day hands EVERY seated player
-- the SAME pool -- generated once per UTC+6 calendar day and reused for
-- every game that day, not re-rolled per game or per player. A genuine
-- new deck_type (rather than layering a hidden flag onto 'sealed_deck')
-- keeps per-deck-type stats/breakdowns elsewhere in the app accurate,
-- matching how every prior deck_type addition has done this.
--
-- New periodic_sealed_pools table stores the actual generated pool,
-- keyed by (period_type, period_start) so a lazy "get or create" read
-- always converges on one row per period regardless of how many games
-- race to be the first to read it that day -- period_type is 'daily' or
-- 'weekly' from the start (rather than a second migration later) since
-- Weekly Sealed Pool's own matches will read/write the exact same table,
-- just under period_type = 'weekly' and a Monday period_start instead of
-- a daily one.
--
-- draft_matches.periodic_sealed_pool_id (nullable) links a match back to
-- the shared pool it was dealt from -- NULL for every match of every
-- OTHER deck_type (including ordinary 'sealed_deck', whose own pools are
-- never persisted centrally at all), non-NULL for 'sealed_pool_of_the_day'
-- (and later 'weekly_sealed_pool'). This is also what
-- GameService::submitDraftDeck() checks to decide whether the new
-- per-rarity deck caps (5 mythic/cap 2, 10 rare/cap 4, 15 uncommon, 20
-- common -- see PERIODIC_SEALED_POOL_RARITY_COUNTS/_CAPS) apply to a
-- given submission, rather than a second games/draft_matches column.
CREATE TABLE IF NOT EXISTS periodic_sealed_pools (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_type ENUM('daily', 'weekly') NOT NULL,
    period_start DATE NOT NULL,
    pool_card_ids JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_periodic_sealed_pools_period (period_type, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE draft_matches
    ADD COLUMN periodic_sealed_pool_id INT UNSIGNED DEFAULT NULL AFTER pool_card_ids,
    ADD CONSTRAINT fk_draft_matches_periodic_sealed_pool FOREIGN KEY (periodic_sealed_pool_id) REFERENCES periodic_sealed_pools (id) ON DELETE SET NULL;

ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'custom', 'custom_duel', 'quick_draft', 'one_of_each', 'winston_draft', 'grid_draft', 'rotisserie_draft', 'tiered_rotisserie_draft', 'chaos_draft', 'sealed_deck', 'sealed_pool_of_the_day') NOT NULL DEFAULT 'structure';

UPDATE schema_version SET version = '1.38.0' WHERE id = 1;

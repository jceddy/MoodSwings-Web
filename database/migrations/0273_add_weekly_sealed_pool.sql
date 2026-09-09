-- Issue #520 part 2/2: "Weekly Sealed Pool" -- the slower-cadence sibling
-- of Sealed Pool of the Day (migration 0272), paired with a lightweight
-- continuous matchmaking queue and a persistent standings/leaderboard.
--
-- Mechanically, a Weekly Sealed Pool match is just an ordinary Sealed
-- Deck match (new deck_type 'weekly_sealed_pool', reusing every bit of
-- Sealed Pool of the Day's own shared-pool/rarity-cap machinery -- see
-- GameService::PERIODIC_SEALED_POOL_DECK_TYPES) whose pool source happens
-- to be that week's shared periodic_sealed_pools row (period_type
-- 'weekly', already supported by that table since migration 0272) instead
-- of a per-player random draw or the daily one. The two new tables below
-- are what's genuinely new: a FIFO pairing queue, and a running per-week
-- record of who's won/lost how many matches.
ALTER TABLE games
    MODIFY COLUMN deck_type ENUM('structure', 'power', 'jceddys_75', 'custom', 'custom_duel', 'quick_draft', 'one_of_each', 'winston_draft', 'grid_draft', 'rotisserie_draft', 'tiered_rotisserie_draft', 'chaos_draft', 'sealed_deck', 'sealed_pool_of_the_day', 'weekly_sealed_pool') NOT NULL DEFAULT 'structure';

-- One row per player currently waiting to be paired -- WeeklySealedPoolQueueService::joinQueue()
-- inserts a row here only when no eligible opponent (see
-- GameService::haveWeeklySealedPoolOpponentsAlreadyPlayed()) was already
-- waiting; a successful pairing deletes the matched opponent's row
-- (the joiner is paired immediately and never gets a row of their own in
-- that case). No period_start column -- the pool a pairing actually draws
-- from is always resolved fresh at pairing time (GameService::
-- currentWeeklySealedPoolId()), so a queue row that happens to survive a
-- Monday-midnight rollover simply pairs into the new week's own pool
-- instead of the old one, with no separate cleanup needed.
CREATE TABLE IF NOT EXISTS weekly_sealed_pool_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    queued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_weekly_sealed_pool_queue_user (user_id),
    CONSTRAINT fk_weekly_sealed_pool_queue_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per (week, player) -- accumulates across every Weekly Sealed
-- Pool match that player completes during periodic_sealed_pool_id's own
-- week (GameService::recordWeeklySealedPoolStandings()), not one row per
-- match. wins/losses are the plain record players actually see; score is
-- the hidden internal ranking value (GameService::
-- WEEKLY_SEALED_POOL_RANKING_POINTS -- win +3/loss -2) used only to sort
-- placement, never displayed directly -- see weeklySealedPoolStandings()'s
-- own docblock for the percentage-placement/percentile it's translated
-- into instead. A row only ever exists for a player who has completed at
-- least one match that week, so "has a row this week" doubles as "is
-- ranked" -- someone who's only queued/mid-match isn't listed at all.
CREATE TABLE IF NOT EXISTS weekly_sealed_pool_standings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    periodic_sealed_pool_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    wins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    losses SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    score INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_weekly_sealed_pool_standings (periodic_sealed_pool_id, user_id),
    CONSTRAINT fk_weekly_sealed_pool_standings_pool FOREIGN KEY (periodic_sealed_pool_id) REFERENCES periodic_sealed_pools (id) ON DELETE CASCADE,
    CONSTRAINT fk_weekly_sealed_pool_standings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.39.0' WHERE id = 1;

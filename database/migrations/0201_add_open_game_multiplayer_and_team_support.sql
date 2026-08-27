-- Issue #116 follow-up: extends open lobby matchmaking (migration 0198,
-- originally scoped to exactly-two-player 'duel'/'draft' listings only)
-- to also support 'standard' (2-4 player free-for-all) and the team
-- formats ('team'/'closed_team', always exactly 4 -- 2 teams of 2).
--
-- The original design let a single joiner instantly complete and claim a
-- listing, since exactly one more player was ever needed. That no longer
-- holds once a listing can need up to 3 more players -- target_player_count
-- (the maintainer's own choice: the creator picks an exact target at
-- posting time, e.g. "3 players", rather than a min/max range with a
-- manual "start now") records how many total seats this listing needs,
-- and open_game_listing_joins accumulates who's joined so far. A listing
-- stays 'open' with a partial roster until joins reach target_player_count,
-- at which point the same createGame() call the original two-player flow
-- always made finally fires, seating the creator plus every recorded
-- joiner. For 'duel' this is always 2 (unchanged behavior); for
-- 'team'/'closed_team' it's always 4, with teams assigned by
-- createGame()'s own existing randomTeams mechanic (the maintainer's own
-- choice over letting the creator hand-pick a partner from strangers, or
-- pure join-order pairing) -- there's no way to know a partner ahead of
-- time when every joiner is a stranger.
ALTER TABLE open_game_listings
    ADD COLUMN target_player_count TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER create_game_params;

-- One row per player who has joined a listing (not counting the creator,
-- who's already on open_game_listings.created_by_user_id). Deliberately
-- its own table rather than a JSON array column on open_game_listings
-- itself -- MatchmakingService::joinOpenGame() runs under a per-listing
-- advisory lock (mirroring GameService::withGameLock()'s own pattern) to
-- keep "count joins, maybe create the game" atomic, and a real table with
-- its own UNIQUE constraint is a second, storage-level guarantee against
-- the same user ever being recorded twice for one listing, on top of that
-- lock, rather than relying on the lock alone.
CREATE TABLE IF NOT EXISTS open_game_listing_joins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_open_game_listing_joins_pair (listing_id, user_id),
    CONSTRAINT fk_open_game_listing_joins_listing FOREIGN KEY (listing_id) REFERENCES open_game_listings (id) ON DELETE CASCADE,
    CONSTRAINT fk_open_game_listing_joins_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.28.57' WHERE id = 1;

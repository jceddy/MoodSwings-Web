-- Issue #116: matchmaking beyond the friends list. First cut, per the
-- issue's own "candidate mechanisms, roughly in order of how much new
-- infrastructure they need" list: an open lobby / public game listing --
-- a player posts a game as "open" instead of naming specific friend
-- opponents (POST /games' own opponent_user_ids), and any other
-- discoverable, non-blocked player can browse and join it. Quick
-- match/random-opponent queues and skill-based matchmaking are explicitly
-- deferred future steps per that same list.
--
-- Scoped for this first cut to exactly-two-player games only -- format
-- 'duel', or format 'draft' with any of its deck types played 2-player.
-- 'standard' (native 2+ player free-for-all, no fixed roster size) and the
-- team formats (which need a full known 4-player roster, including a
-- chosen partner, before anything can start) raise their own open
-- questions about *when* an open listing has "enough" joiners to begin --
-- deferred to a follow-up rather than designing that up front.
--
-- Moderation: joining a stranger's game is exactly the scenario the
-- existing friends-only model sidesteps entirely (see friendships'
-- 'blocked' status, "Accounts/friends" above) -- so a listing is only
-- ever shown to other users if its creator has opted into
-- matchmaking_discoverable, and never shown to (or joinable by) a user
-- who's blocked the creator or vice versa (MatchmakingService checks
-- friendships regardless of who performed the block, same as how a
-- block itself is symmetric once made).
ALTER TABLE users
    ADD COLUMN matchmaking_discoverable TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_custom_content;

-- One row per posted listing. create_game_params is the exact same
-- shape of named arguments GameService::createGame() itself takes
-- (format, deck_type, wins_needed, decklist choices, draft pool
-- choices, etc.) minus created_by_user_id/userIds/partner_user_id/
-- random_teams/bot_* (none of which are known, or meaningful, until a
-- second real player actually joins) -- captured as one JSON blob
-- rather than a column per possible createGame() parameter, the same
-- shape draft_matches.pool_card_ids already stores its own JSON blob
-- instead of one column per pool member.
--
-- status: 'open' (visible/joinable), 'claimed' (a joiner filled it --
-- claimed_by_user_id/claimed_game_id/claimed_at record who and which
-- games row resulted), or 'cancelled' (creator withdrew it, or a join
-- attempt's own createGame() call failed validation and the listing
-- was retired rather than leaving something permanently broken
-- joinable). Rows are kept permanently once claimed/cancelled rather
-- than deleted, the same "keep the audit trail" preference the rest of
-- this schema already follows (e.g. draft_matches, user_lifetime_stats).
CREATE TABLE IF NOT EXISTS open_game_listings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by_user_id INT UNSIGNED NOT NULL,
    create_game_params JSON NOT NULL,
    status ENUM('open', 'claimed', 'cancelled') NOT NULL DEFAULT 'open',
    claimed_by_user_id INT UNSIGNED DEFAULT NULL,
    claimed_game_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claimed_at TIMESTAMP NULL DEFAULT NULL,
    cancelled_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_open_game_listings_status (status),
    CONSTRAINT fk_open_game_listings_creator FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_open_game_listings_claimed_by FOREIGN KEY (claimed_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_open_game_listings_claimed_game FOREIGN KEY (claimed_game_id) REFERENCES games (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.28.54' WHERE id = 1;

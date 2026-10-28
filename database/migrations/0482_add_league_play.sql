-- Issue #171: League play -- a manually started, open-ended event in which each
-- player is granted a randomized Structure deck (once per event, the first time
-- they join it) that is added to a permanent personal collection, and plays
-- best-of-three Duel matches against other League players with decks built only
-- from that collection. See php-app/README.md's "League play" section.
--
-- user_collection_cards: the permanent collection -- one row per (player, catalog
-- card), `copies` only ever grows. Chaos Draft tokens are never granted, so they
-- never appear here.
CREATE TABLE IF NOT EXISTS user_collection_cards (
    user_id INT UNSIGNED NOT NULL,
    card_id SMALLINT UNSIGNED NOT NULL,
    copies INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, card_id),
    CONSTRAINT fk_user_collection_cards_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_collection_cards_card FOREIGN KEY (card_id) REFERENCES cards (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- league_events: started and ended by hand (bin/league_event.php). At most one
-- is 'active' at a time (enforced by LeagueEventRepository::start()); `set_code`
-- is optional, for an event tied to a set release.
CREATE TABLE IF NOT EXISTS league_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    set_code VARCHAR(16) NULL,
    status ENUM('active', 'ended') NOT NULL DEFAULT 'active',
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- league_participants: one row per (event, player) once they have joined and been
-- granted their deck -- the row's existence is what makes the grant happen only
-- once per event.
CREATE TABLE IF NOT EXISTS league_participants (
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    granted_card_ids LONGTEXT NOT NULL,
    PRIMARY KEY (event_id, user_id),
    CONSTRAINT fk_league_participants_event FOREIGN KEY (event_id) REFERENCES league_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_league_participants_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- league_queue: players waiting for an opponent, with a snapshot of the deck they
-- queued with (validated against their collection when they queued). One entry
-- per player at a time.
CREATE TABLE IF NOT EXISTS league_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    deck_name VARCHAR(120) NULL,
    deck_card_ids LONGTEXT NOT NULL,
    queued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_league_queue_user (user_id),
    KEY idx_league_queue_event (event_id),
    CONSTRAINT fk_league_queue_event FOREIGN KEY (event_id) REFERENCES league_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_league_queue_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- league_matches: one row per paired match. game_match_id is the best-of-three
-- wrapper (game_matches.id); no foreign key to it on purpose, so a record of who
-- has already played whom -- and the W/L tally -- survives game cleanup.
-- LeagueService::syncMatches() copies the result over once the match completes.
CREATE TABLE IF NOT EXISTS league_matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    user1_id INT UNSIGNED NOT NULL,
    user2_id INT UNSIGNED NOT NULL,
    game_match_id BIGINT UNSIGNED NOT NULL,
    status ENUM('in_progress', 'completed', 'abandoned') NOT NULL DEFAULT 'in_progress',
    winner_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idx_league_matches_event_user1 (event_id, user1_id),
    KEY idx_league_matches_event_user2 (event_id, user2_id),
    KEY idx_league_matches_game_match (game_match_id),
    CONSTRAINT fk_league_matches_event FOREIGN KEY (event_id) REFERENCES league_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.63.0' WHERE id = 1;

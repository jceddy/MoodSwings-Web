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

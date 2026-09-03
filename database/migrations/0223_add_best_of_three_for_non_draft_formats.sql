-- Issue #90: best-of-three matches (with sideboarding) for the non-draft
-- formats -- Duel, Open Team Play, and Closed Team Play -- mirroring the
-- best-of-three match wrapper draft-based deck_types have had since
-- migration 0027, but purpose-built for these formats instead of reusing
-- draft_matches/draft_match_players (whose pool_source/pool_card_ids/
-- drafted_card_ids columns are all meaningless here -- there's no shared
-- draft pool, just up to 3 separate `games` rows).
--
-- Unlike draft_match_players, this feature needs no per-user row at all:
-- structure/power/jceddy's 75/one of each decks are freshly rebuilt by
-- startGame() every game exactly like a standalone game already does, and
-- custom_duel's own decklist (issue #140/#19) already lives on
-- game_players.custom_deck_card_ids, scoped to a single `games` row --
-- both need nothing extra to "start fresh" for game 2/3 (custom_duel
-- players just resubmit via the exact same POST /games/decklist call,
-- which is this feature's whole "sideboarding" story: freely edit and
-- resubmit, no separate sideboard pool or swap-count limit). The one
-- deck_type needing explicit carry-forward is 'custom' (a single
-- table-wide decklist supplied once at createGame() time, with no
-- resubmission flow of its own) -- GameService::advanceGameMatch() copies
-- custom_deck_name/custom_deck_card_ids onto the new game row itself
-- rather than adding a table for it.
--
-- A match's own win count is likewise never stored -- it's cheap to
-- recompute from the (at most 3) `games` rows sharing a game_match_id, by
-- resolving each one's winner_game_player_id back to its user_id (see
-- GameService::advanceGameMatch()/gameMatchSummaryFor()). This also
-- correctly covers Team Play/Closed Team Play without a separate
-- winner_team_id column here: a team game's own winner_game_player_id is
-- always that game's winning team's lowest-seat_order member (the same
-- "representative" convention completeGameByResignation()/
-- finishTeamScoringAndAdvance() already use), and since a match's seats
-- (and therefore each team's own representative) are carried forward
-- unchanged game to game, tallying by that representative's user_id
-- across the match is equivalent to tallying by team.
CREATE TABLE IF NOT EXISTS game_matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    format ENUM('duel', 'team', 'closed_team') NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    status ENUM('in_progress', 'completed') NOT NULL DEFAULT 'in_progress',
    winner_user_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_game_matches_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_matches_winner FOREIGN KEY (winner_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- games.match_game_number (migration 0027) is reused as-is for this
-- feature's own up-to-3 numbering -- a game belongs to at most one of
-- draft_match_id/game_match_id, never both, so one shared numbering
-- column is unambiguous.
ALTER TABLE games
    ADD COLUMN game_match_id BIGINT UNSIGNED DEFAULT NULL AFTER draft_match_id,
    ADD CONSTRAINT fk_games_game_match FOREIGN KEY (game_match_id) REFERENCES game_matches (id) ON DELETE SET NULL;

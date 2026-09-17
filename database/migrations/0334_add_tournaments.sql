-- Issue #91: a tournament system for organizing multiple players/matches
-- into a structured event -- single elimination, double elimination, or
-- Swiss rounds, selectable at creation -- rather than the purely ad hoc
-- one-off games the lobby otherwise creates. Builds on best-of-three
-- matches (issue #90, migration 0223) and drafting (migration 0027) as
-- the underlying per-matchup unit: each bracket "match" is exactly one
-- ordinary game-creation call away from being a plain game, a
-- best-of-three game_matches match, or a draft_matches match -- a
-- tournament never re-plays any of that, it just seats two participants
-- against each other via GameService::createGame() and watches for the
-- result. Scoped to 1v1 matchups only for v1 (team formats need a full
-- 4-seat roster with a chosen partner, which has no natural meaning
-- against a lone bracket opponent).
--
-- tournaments.match_params is the exact same shape
-- GameService::createGame() itself takes, minus createdByUserId/userIds/
-- partnerUserId/randomTeams/bot_* -- identical in spirit to
-- open_game_listings.create_game_params (migration 0198), just fixed
-- once for every matchup in the whole event rather than negotiated at
-- join time. registration_mode ('invite_only'/'open') mirrors the
-- existing friend-invite-vs-open-lobby split (POST /games'
-- opponent_user_ids vs. open_game_listings) rather than inventing a
-- third model.
--
-- swiss_round_count is only meaningful for bracket_type='swiss' -- NULL
-- means "let TournamentService pick ceil(log2(participant count)) once
-- registration closes." max_participants is required for an 'open'
-- tournament (how else would it know when it's full?) but may be left
-- NULL for 'invite_only' (the invite list itself is the cap).
CREATE TABLE IF NOT EXISTS tournaments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    bracket_type ENUM('single_elimination', 'double_elimination', 'swiss') NOT NULL,
    registration_mode ENUM('invite_only', 'open') NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    match_params JSON NOT NULL,
    swiss_round_count TINYINT UNSIGNED DEFAULT NULL,
    min_participants TINYINT UNSIGNED NOT NULL DEFAULT 2,
    max_participants TINYINT UNSIGNED DEFAULT NULL,
    status ENUM('registration', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'registration',
    winner_user_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_tournaments_status (status),
    CONSTRAINT fk_tournaments_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_tournaments_winner FOREIGN KEY (winner_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per invited/registered/joined user. 'invited' only ever
-- happens for an invite_only tournament (the creator names specific
-- users up front, same as POST /games' opponent_user_ids); 'open'
-- tournaments only ever create rows straight into 'joined' (anyone
-- discoverable can register, nobody to invite ahead of time). Win/loss
-- counts and bracket standing are never stored here -- exactly the
-- game_matches convention of recomputing from the (at most handful of)
-- rows that reference this participant, here tournament_matches --
-- since seed and status are the only two things that can't be derived.
-- seed is assigned once, when the tournament actually starts (random
-- among 'joined' participants, since nothing about registration order
-- should predict bracket strength).
CREATE TABLE IF NOT EXISTS tournament_participants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournament_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('invited', 'joined', 'declined', 'withdrawn') NOT NULL DEFAULT 'joined',
    seed TINYINT UNSIGNED DEFAULT NULL,
    joined_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tournament_participants_user (tournament_id, user_id),
    CONSTRAINT fk_tournament_participants_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments (id) ON DELETE CASCADE,
    CONSTRAINT fk_tournament_participants_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 'bracket' distinguishes the three concurrent round sequences a
-- double-elimination event needs (winners/losers/grand_final) from the
-- single sequence single-elimination ('single') and Swiss ('swiss')
-- each only ever have one of. round_number restarts at 1 within each
-- bracket, so e.g. ('winners', 2) and ('losers', 2) coexist.
CREATE TABLE IF NOT EXISTS tournament_rounds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournament_id BIGINT UNSIGNED NOT NULL,
    bracket ENUM('single', 'winners', 'losers', 'grand_final', 'swiss') NOT NULL,
    round_number TINYINT UNSIGNED NOT NULL,
    status ENUM('pending', 'in_progress', 'completed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tournament_rounds_number (tournament_id, bracket, round_number),
    CONSTRAINT fk_tournament_rounds_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per bracket slot/pairing. Exactly one of game_id/game_match_id/
-- draft_match_id is ever set (mirroring games' own draft_match_id vs.
-- game_match_id split) once both participants are known and the
-- underlying game(s) have actually been created via createGame() --
-- whichever of the three the resulting `games` row's own draft_match_id/
-- game_match_id resolves to (or the bare game_id itself for a
-- non-best-of-three, non-draft matchup). This is also how
-- GameService::advanceTournamentMatch() finds its way back from a
-- completing game to the tournament_matches row that owns it: resolve
-- the finishing game's own draft_match_id/game_match_id (or its own id),
-- then look up whichever of these three columns matches.
--
-- participant2_id is left NULL for a bye slot (an odd bracket-round
-- survivor count) -- status 'bye', participant1 auto-advances via
-- winner_advances_to_match_id without any game ever being created.
-- winner_advances_to_match_id/_slot wire a single/double-elimination
-- bracket's tree explicitly rather than deriving it from slot arithmetic
-- (which double-elimination's losers bracket doesn't follow cleanly once
-- byes are involved) -- both NULL for a bracket's final match, and for
-- every Swiss round match (Swiss pairs fresh from standings each round
-- instead of a fixed tree). loser_advances_to_match_id/_slot are only
-- ever set for a double-elimination winners-bracket match (dropping its
-- loser into the losers bracket).
CREATE TABLE IF NOT EXISTS tournament_matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournament_id BIGINT UNSIGNED NOT NULL,
    round_id BIGINT UNSIGNED NOT NULL,
    slot TINYINT UNSIGNED NOT NULL,
    participant1_id BIGINT UNSIGNED DEFAULT NULL,
    participant2_id BIGINT UNSIGNED DEFAULT NULL,
    winner_participant_id BIGINT UNSIGNED DEFAULT NULL,
    game_id INT UNSIGNED DEFAULT NULL,
    game_match_id BIGINT UNSIGNED DEFAULT NULL,
    draft_match_id BIGINT UNSIGNED DEFAULT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'bye') NOT NULL DEFAULT 'pending',
    winner_advances_to_match_id BIGINT UNSIGNED DEFAULT NULL,
    winner_advances_to_slot TINYINT UNSIGNED DEFAULT NULL,
    loser_advances_to_match_id BIGINT UNSIGNED DEFAULT NULL,
    loser_advances_to_slot TINYINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tournament_matches_slot (round_id, slot),
    KEY idx_tournament_matches_game_match (game_match_id),
    KEY idx_tournament_matches_draft_match (draft_match_id),
    CONSTRAINT fk_tournament_matches_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments (id) ON DELETE CASCADE,
    CONSTRAINT fk_tournament_matches_round FOREIGN KEY (round_id) REFERENCES tournament_rounds (id) ON DELETE CASCADE,
    CONSTRAINT fk_tournament_matches_p1 FOREIGN KEY (participant1_id) REFERENCES tournament_participants (id) ON DELETE SET NULL,
    CONSTRAINT fk_tournament_matches_p2 FOREIGN KEY (participant2_id) REFERENCES tournament_participants (id) ON DELETE SET NULL,
    CONSTRAINT fk_tournament_matches_winner FOREIGN KEY (winner_participant_id) REFERENCES tournament_participants (id) ON DELETE SET NULL,
    CONSTRAINT fk_tournament_matches_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE SET NULL,
    CONSTRAINT fk_tournament_matches_game_match FOREIGN KEY (game_match_id) REFERENCES game_matches (id) ON DELETE SET NULL,
    CONSTRAINT fk_tournament_matches_draft_match FOREIGN KEY (draft_match_id) REFERENCES draft_matches (id) ON DELETE SET NULL,
    CONSTRAINT fk_tournament_matches_winner_advances FOREIGN KEY (winner_advances_to_match_id) REFERENCES tournament_matches (id) ON DELETE SET NULL,
    CONSTRAINT fk_tournament_matches_loser_advances FOREIGN KEY (loser_advances_to_match_id) REFERENCES tournament_matches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.47.0' WHERE id = 1;

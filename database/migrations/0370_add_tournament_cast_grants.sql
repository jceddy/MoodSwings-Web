-- Tournament spectator mode (issue #238): a trusted-viewer-only mode that
-- reveals hands and pending-decision internals for a still-in_progress
-- tournament match, for casting/streaming purposes. Deliberately NOT the
-- plain spectate_code mechanism from issue #128 -- that mechanism's whole
-- design premise is "holding a code never reveals hands before the game
-- ends," so a live-hands caster needs a distinctly more trusted,
-- organizer-granted permission instead. A tournament's own creator
-- (tournaments.created_by_user_id) always has cast access implicitly;
-- this table is for the *additional* users the creator explicitly grants
-- it to (e.g. a dedicated caster who isn't otherwise a tournament
-- participant). See TournamentService::grantCastAccess()/hasCastAccess().
CREATE TABLE IF NOT EXISTS tournament_cast_grants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournament_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    granted_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tournament_cast_grants (tournament_id, user_id),
    CONSTRAINT fk_tournament_cast_grants_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments (id) ON DELETE CASCADE,
    CONSTRAINT fk_tournament_cast_grants_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_tournament_cast_grants_granted_by FOREIGN KEY (granted_by_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.52.0' WHERE id = 1;

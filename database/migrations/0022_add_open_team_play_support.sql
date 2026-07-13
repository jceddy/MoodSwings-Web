-- Open Team Play (issue #86): four players, fixed 2v2 teams sitting
-- adjacent (game_players.team_id, already provisioned by 0004 for exactly
-- this), scores added together per team rather than tracked per player.
-- format's existing 'team' value already covers both Open and Closed
-- variants ("Other Ways to Play") -- only Open is implemented so far, and
-- both share this same schema.
--
-- winner_team_id is the authoritative record of which TEAM won a round/
-- game -- winner_game_player_id (existing) still gets set too, to one
-- representative teammate (whoever scored higher that round, ties by
-- lower seat_order), purely so existing FK/display code that only knows
-- about individual winners still has something sane to point at. Only
-- winner_team_id is ever used for team win-counting (see
-- GameService::totalWinsForTeam()) -- a representative player's own
-- individual win count would be wrong, since a *different* teammate could
-- have had the higher score in an earlier round that same team won.
ALTER TABLE games
    ADD COLUMN winner_team_id TINYINT UNSIGNED DEFAULT NULL AFTER winner_game_player_id;

-- team_turn_1/2_game_player_id record which specific teammate actually
-- took the round's first/second turn, once each team's own "who goes"
-- decision (see game_team_decisions below) resolves -- turns 3 and 4 are
-- never stored: they're forced (whichever teammate on each team HASN'T
-- gone yet this round), derivable at read time from team_id membership
-- once both of these are known, so there's nothing left to persist for
-- them.
ALTER TABLE game_rounds
    ADD COLUMN winner_team_id TINYINT UNSIGNED DEFAULT NULL AFTER winner_game_player_id,
    ADD COLUMN team_turn_1_game_player_id INT UNSIGNED DEFAULT NULL AFTER current_turn_game_player_id,
    ADD COLUMN team_turn_2_game_player_id INT UNSIGNED DEFAULT NULL AFTER team_turn_1_game_player_id,
    ADD CONSTRAINT fk_game_rounds_team_turn_1 FOREIGN KEY (team_turn_1_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_game_rounds_team_turn_2 FOREIGN KEY (team_turn_2_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL;

-- A team-format round's turn order isn't a fixed seat rotation -- see
-- "Open Team Play" in php-app/README.md -- each team gets to choose which
-- of its two members takes that team's next turn, and (separately, at
-- round end) which member of the losing team gets its one shared draw
-- card. The rules ask for a *joint* team decision, but the engine needs
-- one actual HTTP request to act on: modeled here as propose (either
-- teammate may answer first) then confirm (specifically the OTHER
-- teammate must approve, or reject to send it back to propose).
-- candidate_game_player_ids is always that team's own two members --
-- redundant with team_id, but kept inline so a decision's own eligible
-- answerers never need a second query to resolve.
CREATE TABLE IF NOT EXISTS game_team_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    game_round_id INT UNSIGNED NOT NULL,
    team_id TINYINT UNSIGNED NOT NULL,
    decision_type ENUM('turn_order', 'draw_recipient') NOT NULL,
    phase ENUM('propose', 'confirm') NOT NULL DEFAULT 'propose',
    candidate_game_player_ids JSON NOT NULL,
    proposer_game_player_id INT UNSIGNED DEFAULT NULL,
    proposed_game_player_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    -- Same "NULL is distinct in a UNIQUE index" trick as
    -- uq_pending_batches_one_open_per_round (migration 0011): any number
    -- of resolved rows can share a round, but a second still-open one
    -- collides immediately rather than leaving two simultaneously live.
    active_marker TINYINT UNSIGNED GENERATED ALWAYS AS (IF(resolved_at IS NULL, 1, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_team_decisions_one_open_per_round (game_round_id, active_marker),
    CONSTRAINT fk_team_decisions_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_team_decisions_round FOREIGN KEY (game_round_id) REFERENCES game_rounds (id) ON DELETE CASCADE,
    CONSTRAINT fk_team_decisions_proposer FOREIGN KEY (proposer_game_player_id) REFERENCES game_players (id) ON DELETE RESTRICT,
    CONSTRAINT fk_team_decisions_proposed FOREIGN KEY (proposed_game_player_id) REFERENCES game_players (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.3.0' WHERE id = 1;

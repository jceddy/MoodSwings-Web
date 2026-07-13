-- Closed Team Play (issue #87): same 4-player 2v2 structure as Open Team
-- Play (migration 0022), but partners sit ACROSS from each other rather
-- than adjacent (so a plain clockwise seat rotation already alternates
-- between teams -- see GameService::seatOrderForClosedTeamGame()), hands
-- stay private between teammates (no equivalent of Open Team Play's
-- you.teammate_hand), and only ONE live "who goes first" choice exists
-- per round (the winning team picks a single leader; round 1's leader is
-- simply randomized) rather than Open Team Play's two forced turn
-- placements -- so this format reuses game_team_decisions'
-- 'turn_order'/'draw_recipient' machinery for that single choice and the
-- losing team's shared draw, but does NOT need its own
-- team_turn_1/2_game_player_id columns: the chosen leader is written
-- straight into game_rounds.first_game_player_id (see
-- GameService::applyClosedTeamLeaderDecision()), and turn order for the
-- rest of the round is the exact same rotate(seatOrder, first_game_player_id)
-- primitive every non-team format already uses.
ALTER TABLE games MODIFY COLUMN format ENUM('standard', 'duel', 'team', 'closed_team') NOT NULL DEFAULT 'standard';

-- Closed Team Play's own pregame mechanic with no Open Team Play analog:
-- after everyone's dealt their starting hand, each of the 4 players must
-- pass exactly 2 cards to their teammate face down, BEFORE seeing what
-- their own teammate passed them -- a blind, simultaneous exchange, not a
-- sequential one. Each player's own row records their choice the moment
-- they submit it (so it can never be changed after the fact, preserving
-- "you have to pass your cards before looking at what your teammate
-- passed"); GameService::submitInitialCardPass() applies one team's
-- actual card transfer the moment BOTH of that team's rows exist
-- (independently of the other team's own pace), and only unfreezes round
-- 1's first turn once all 4 rows exist. One row per player per game --
-- there's exactly one such exchange, right at the start of the game, never
-- repeated.
CREATE TABLE IF NOT EXISTS game_initial_card_passes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    game_player_id INT UNSIGNED NOT NULL,
    card_ids JSON NOT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_initial_card_pass_per_player (game_id, game_player_id),
    CONSTRAINT fk_initial_card_pass_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_initial_card_pass_player FOREIGN KEY (game_player_id) REFERENCES game_players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.4.0' WHERE id = 1;

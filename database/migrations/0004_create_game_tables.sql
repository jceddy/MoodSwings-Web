-- Match-level tables for actually playing Mood Swings, layered on top of
-- the account/friends tables from earlier migrations and the card catalog
-- from 0003. A "game" is one played-to-completion session; "rounds" are
-- the repeated play-a-mood-then-score cycles within it (see the Extended
-- Rules document).

-- format covers the variants from "Other Ways to Play": 'standard' (the
-- base rules), 'duel' (2-player), and 'team' (uses game_players.team_id
-- to pair players up). Draft-style deck construction isn't modeled yet --
-- deferred until that format is actually implemented, since it changes
-- how a game's card pool is assembled rather than anything here.
CREATE TABLE IF NOT EXISTS games (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    format ENUM('standard', 'duel', 'team') NOT NULL DEFAULT 'standard',
    status ENUM('waiting', 'in_progress', 'completed', 'abandoned') NOT NULL DEFAULT 'waiting',
    created_by_user_id INT UNSIGNED NOT NULL,
    wins_needed TINYINT UNSIGNED NOT NULL DEFAULT 3,
    winner_game_player_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_games_created_by (created_by_user_id),
    CONSTRAINT fk_games_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per seated player in a game. seat_order is turn order (play
-- proceeds clockwise, i.e. in ascending seat_order, per the rules).
-- team_id only means anything for the 'team' format and pairs two players
-- together (see FriendshipRepository-style user pairing for a similar
-- idea, though teams aren't unordered pairs the way friendships are).
CREATE TABLE IF NOT EXISTS game_players (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    seat_order TINYINT UNSIGNED NOT NULL,
    team_id TINYINT UNSIGNED DEFAULT NULL,
    left_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_game_players_seat (game_id, seat_order),
    UNIQUE KEY uq_game_players_user (game_id, user_id),
    CONSTRAINT fk_game_players_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_players_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- games.winner_game_player_id can't be declared inline above since
-- game_players doesn't exist yet at that point.
ALTER TABLE games
    ADD CONSTRAINT fk_games_winner FOREIGN KEY (winner_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL;

-- One row per round of play within a game. hurt_feelings_game_player_id is
-- deliberately modeled here as an attribute of the round rather than a row
-- in `cards`: physically Hurt Feelings is a marker/token, never a card
-- that's in a deck, hand, or discard pile, and who holds it is entirely
-- determined by the previous round's score (the lowest scorer gets it for
-- the next round; ties go to whoever took their turn latest). It's set
-- when a round begins and grants that player the ability to play an extra
-- mood on their turn that round.
CREATE TABLE IF NOT EXISTS game_rounds (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    round_number SMALLINT UNSIGNED NOT NULL,
    first_game_player_id INT UNSIGNED NOT NULL,
    hurt_feelings_game_player_id INT UNSIGNED DEFAULT NULL,
    status ENUM('in_progress', 'scored') NOT NULL DEFAULT 'in_progress',
    winner_game_player_id INT UNSIGNED DEFAULT NULL,
    wins_awarded TINYINT UNSIGNED NOT NULL DEFAULT 1,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    scored_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_game_rounds_number (game_id, round_number),
    CONSTRAINT fk_game_rounds_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_rounds_first_player FOREIGN KEY (first_game_player_id) REFERENCES game_players (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_rounds_hurt_feelings FOREIGN KEY (hurt_feelings_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL,
    CONSTRAINT fk_game_rounds_winner FOREIGN KEY (winner_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each player's computed score at the moment a round was scored. Mood
-- values are dynamic (most cards' values depend on the current board
-- state), so this snapshot -- not a live recomputation from game_cards --
-- is the historical record of what actually happened, even after the
-- moods that produced it move around or leave play. wins_awarded on
-- game_rounds (usually 1) covers effects like Corruption that let a
-- round's winner count for two rounds instead of one.
CREATE TABLE IF NOT EXISTS game_round_scores (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_round_id INT UNSIGNED NOT NULL,
    game_player_id INT UNSIGNED NOT NULL,
    score SMALLINT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_game_round_scores_player (game_round_id, game_player_id),
    CONSTRAINT fk_game_round_scores_round FOREIGN KEY (game_round_id) REFERENCES game_rounds (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_round_scores_player FOREIGN KEY (game_player_id) REFERENCES game_players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where every physical card in a game currently is. Each of the 133 cards
-- gets exactly one game_cards row per game (there's only one copy of each
-- in the shared deck), and this table is the single source of truth for
-- "where is this card right now" -- moving a card between zones is an
-- UPDATE of its row, not a move between separate per-zone tables.
--
-- copied_card_id supports Creativity: while in play "as a copy" of another
-- mood, card_id still identifies the physical Creativity card (so effects
-- that care what a card actually is -- e.g. copying a Creativity copies
-- whatever *it's* a copy of, not Creativity itself -- resolve correctly),
-- while copied_card_id says which card's rules text and value it's
-- currently using. NULL means it's not currently a copy of anything.
--
-- Suppression (several cards suppress a mood so it scores as 0 without
-- removing it from play) is tracked here rather than as a separate zone,
-- since a suppressed mood is still fully in play for every other purpose
-- (color counts, "moodiest opponent", etc. all still see it).
CREATE TABLE IF NOT EXISTS game_cards (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    card_id SMALLINT UNSIGNED NOT NULL,
    zone ENUM('deck', 'discard', 'hand', 'in_play') NOT NULL,
    owner_game_player_id INT UNSIGNED DEFAULT NULL,
    deck_position SMALLINT UNSIGNED DEFAULT NULL,
    copied_card_id SMALLINT UNSIGNED DEFAULT NULL,
    is_suppressed TINYINT(1) NOT NULL DEFAULT 0,
    suppression_expiry ENUM('end_of_round', 'while_source_in_play') DEFAULT NULL,
    suppression_source_game_card_id INT UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_game_cards_card (game_id, card_id),
    KEY idx_game_cards_owner_zone (owner_game_player_id, zone),
    KEY idx_game_cards_zone (game_id, zone),
    CONSTRAINT fk_game_cards_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_cards_card FOREIGN KEY (card_id) REFERENCES cards (id),
    CONSTRAINT fk_game_cards_copied_card FOREIGN KEY (copied_card_id) REFERENCES cards (id),
    CONSTRAINT fk_game_cards_owner FOREIGN KEY (owner_game_player_id) REFERENCES game_players (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_cards_suppression_source FOREIGN KEY (suppression_source_game_card_id) REFERENCES game_cards (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only history of every action taken during a game, independent of
-- (and in addition to) the current-state tables above, so a game can be
-- studied or replayed turn-by-turn after the fact even once the board
-- state that produced it has moved on. `id` is a bigint so it can keep
-- growing across every game on the site, and doubles as the ordering key
-- within a game (alongside created_at) since two events in the same
-- request can otherwise land on the same timestamp.
--
-- event_type plus a JSON details payload is deliberate rather than a
-- column-per-effect design: with 133 distinct card effects, each with its
-- own choices, targets, and quantities, modeling every possible action as
-- dedicated columns isn't practical. details' shape is defined by
-- event_type (e.g. a 'mood_played' event's details might hold the chosen
-- color/number/targets for that card's effect).
CREATE TABLE IF NOT EXISTS game_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    game_round_id INT UNSIGNED DEFAULT NULL,
    acting_game_player_id INT UNSIGNED DEFAULT NULL,
    event_type VARCHAR(40) NOT NULL,
    card_id SMALLINT UNSIGNED DEFAULT NULL,
    details JSON DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_game_events_game (game_id, id),
    KEY idx_game_events_round (game_round_id),
    CONSTRAINT fk_game_events_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_events_round FOREIGN KEY (game_round_id) REFERENCES game_rounds (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_events_player FOREIGN KEY (acting_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL,
    CONSTRAINT fk_game_events_card FOREIGN KEY (card_id) REFERENCES cards (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

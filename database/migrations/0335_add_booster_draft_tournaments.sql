-- Booster Draft: a tournament-only draft format. Up to 8 players per
-- "pod" each open two 15-card boosters (a guaranteed 1 mythic/2 rare/
-- 4 uncommon/8 common -- see BoosterPackBuilder's own docblock) and
-- draft them the traditional way: the
-- first booster passed left, the second passed right, one card taken
-- from whichever booster reaches you each round until both are fully
-- drafted (15 rounds, 2 picks per round -- see TournamentPodRepository's
-- own docblock for the round-robin math this relies on). Every player
-- nets exactly 30 cards regardless of pod size. A tournament with more
-- than 8 joined participants splits them into multiple pods (as even as
-- possible, each <=8 -- see BoosterDraftPodBuilder), but the pod is
-- purely for drafting/deckbuilding: once every pod finishes, the
-- tournament's own bracket/Swiss pairing mixes players across pods
-- freely, exactly like any other tournament -- see TournamentService's
-- own startTournament()/maybeFinalizeDraftingPhase().
--
-- A pod's own drafted pool is never itself played out as a game --
-- each participant instead builds (and later freely re-sideboards,
-- every single tournament match, from their own full 30-card pool --
-- game_players.custom_deck_allowed_card_ids below) a >=12-card deck for
-- the tournament's real matches, played as ordinary 'custom_duel' games
-- (format 'duel') the same way "Power (Custom Decks)" tournament
-- matches already are.
ALTER TABLE tournaments
    MODIFY COLUMN status ENUM('registration', 'drafting', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'registration';

-- One row per pod. current_round advances (1-15) only once every seated
-- participant has picked from BOTH the left- and right-moving booster
-- currently at their seat for that round (see tournament_pod_picks
-- below) -- status flips to 'completed' the moment round 15 finishes,
-- at which point each participant's own 30 picks are copied into
-- tournament_participants.draft_pool_card_ids.
CREATE TABLE IF NOT EXISTS tournament_pods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournament_id BIGINT UNSIGNED NOT NULL,
    pod_number TINYINT UNSIGNED NOT NULL,
    current_round TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('drafting', 'completed') NOT NULL DEFAULT 'drafting',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tournament_pods_number (tournament_id, pod_number),
    CONSTRAINT fk_tournament_pods_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- seat_order (0-indexed) is what the left/right circulation math is
-- computed from -- see TournamentPodRepository's own docblock. A
-- participant belongs to at most one pod for the whole tournament
-- (uq_tournament_pod_participants_participant).
CREATE TABLE IF NOT EXISTS tournament_pod_participants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pod_id BIGINT UNSIGNED NOT NULL,
    participant_id BIGINT UNSIGNED NOT NULL,
    seat_order TINYINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tournament_pod_participants_seat (pod_id, seat_order),
    UNIQUE KEY uq_tournament_pod_participants_participant (participant_id),
    CONSTRAINT fk_tournament_pod_participants_pod FOREIGN KEY (pod_id) REFERENCES tournament_pods (id) ON DELETE CASCADE,
    CONSTRAINT fk_tournament_pod_participants_participant FOREIGN KEY (participant_id) REFERENCES tournament_participants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Two rows per seated pod participant (one 'left', one 'right') --
-- remaining_card_ids shrinks by one card every round as it's picked
-- from, by whichever seat the left/right circulation math currently
-- places it at (see TournamentPodRepository::seatHoldingBooster()) --
-- never by opener_pod_participant_id itself past round 1. A booster's
-- own opener is who it started with (round 1), not who currently holds
-- it.
CREATE TABLE IF NOT EXISTS tournament_pod_boosters (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pod_id BIGINT UNSIGNED NOT NULL,
    opener_pod_participant_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('left', 'right') NOT NULL,
    remaining_card_ids JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tournament_pod_boosters_opener (opener_pod_participant_id, direction),
    CONSTRAINT fk_tournament_pod_boosters_pod FOREIGN KEY (pod_id) REFERENCES tournament_pods (id) ON DELETE CASCADE,
    CONSTRAINT fk_tournament_pod_boosters_opener FOREIGN KEY (opener_pod_participant_id) REFERENCES tournament_pod_participants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An append-only pick log, never updated -- both how a pick is recorded
-- and (via COUNT) how "has every seat picked both directions for round
-- N yet" is derived, the same "derive from the handful of rows that
-- reference it" convention tournament_participants' own win/loss counts
-- already follow. The UNIQUE key doubles as the "can't pick twice for
-- the same booster direction in the same round" guard.
CREATE TABLE IF NOT EXISTS tournament_pod_picks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pod_participant_id BIGINT UNSIGNED NOT NULL,
    round TINYINT UNSIGNED NOT NULL,
    direction ENUM('left', 'right') NOT NULL,
    card_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tournament_pod_picks_round (pod_participant_id, round, direction),
    CONSTRAINT fk_tournament_pod_picks_participant FOREIGN KEY (pod_participant_id) REFERENCES tournament_pod_participants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- draft_pool_card_ids (JSON, an int[] of exactly 30 catalog card ids,
-- duplicates allowed -- the same booster can appear in both of a
-- player's own boosters) is set once, when this participant's own pod
-- finishes drafting. current_deck_card_ids (JSON, an int[] subset of
-- draft_pool_card_ids honoring each card's own multiplicity in the
-- pool, >=12 cards) is null until their first tournament match's own
-- deck submission, and overwritten every time they submit a new one
-- (every match, including game 2/3 of a best-of-three) -- see
-- GameService::submitCustomDuelDeck()'s own onCustomDuelDeckSubmitted()
-- hook. Both null for every non-booster_draft tournament.
ALTER TABLE tournament_participants
    ADD COLUMN draft_pool_card_ids JSON DEFAULT NULL AFTER seed,
    ADD COLUMN current_deck_card_ids JSON DEFAULT NULL AFTER draft_pool_card_ids;

-- A seat's own restriction to a specific pool of card ids (JSON int[],
-- honoring multiplicity) for a 'custom_duel' game -- set at createGame()
-- time only for a Booster Draft tournament match (TournamentService's
-- own startMatchGame(), from the seated participant's own
-- tournament_participants.draft_pool_card_ids above), null for every
-- other custom_duel game. submitCustomDuelDeck() enforces it, when set,
-- IN ADDITION to the game's own duel_deck_rules -- see its own
-- docblock.
ALTER TABLE game_players
    ADD COLUMN custom_deck_allowed_card_ids JSON DEFAULT NULL AFTER custom_deck_sideboard_card_ids;

UPDATE schema_version SET version = '1.48.0' WHERE id = 1;

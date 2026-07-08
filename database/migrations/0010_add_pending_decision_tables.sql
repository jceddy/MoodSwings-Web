-- Support for cards whose real rules text gives the decision to a player
-- OTHER than the one whose turn it is (e.g. Compulsion: "That player
-- chooses a card from their hand and gives it to you") -- previously
-- approximated with a random pick since there was no way for a second,
-- different logged-in player to inject an answer mid-play. A play that
-- needs one of these now pauses instead of completing: the card has
-- already been moved into play, its cost already paid, its play grant
-- already consumed (see MoodPlayService::playMood()) -- only the
-- after-playing resolution itself is on hold, waiting on the targeted
-- player(s)' own response via a later, separate request.
--
-- A "batch" is one afterPlaying() invocation's whole decision -- either
-- the played card's own, or one of its Duplicity repeats (invocation_seq
-- 1+). It carries BOTH the original top-level PlayerChoices (needed later
-- for the reactToAnotherPlay() reaction loop, which per MoodEffect's own
-- contract always reads the top-level choices, never an invocation's own)
-- and this invocation's own choices (needed by the card's own
-- resolveDecisions()) -- they differ once a Duplicity repeat is involved,
-- where the invocation's own choices are the duplicity_repeat_choices
-- sub-bag rather than the top-level request.
--
-- A round has at most one open batch at a time (the whole round is frozen
-- while one is outstanding -- see GameService::playMood()/pass()), and a
-- batch can contain several individual decisions (one row each) for cards
-- that ask more than one player something -- e.g. Disillusionment asks
-- every other player, in turn order, one at a time; Suspicion asks each
-- of however many players were chosen. Only the lowest-step_index
-- unresolved row in the open batch is ever actively prompted.
CREATE TABLE IF NOT EXISTS game_pending_decision_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    game_round_id INT UNSIGNED NOT NULL,
    played_card_id SMALLINT UNSIGNED NOT NULL,
    invocation_seq TINYINT UNSIGNED NOT NULL DEFAULT 0,
    initiating_game_player_id INT UNSIGNED NOT NULL,
    top_level_choices JSON NOT NULL,
    invocation_choices JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_pending_batches_round_open (game_round_id, resolved_at),
    CONSTRAINT fk_pending_batches_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_pending_batches_round FOREIGN KEY (game_round_id) REFERENCES game_rounds (id) ON DELETE CASCADE,
    CONSTRAINT fk_pending_batches_card FOREIGN KEY (played_card_id) REFERENCES cards (id),
    CONSTRAINT fk_pending_batches_initiator FOREIGN KEY (initiating_game_player_id) REFERENCES game_players (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- field is the CardChoiceSchema-shaped prompt to show target_game_player_id
-- (type/scope/filter/multi/count, same vocabulary GET /games/state already
-- uses for choice_fields -- see CardChoiceSchema.php's docblock), computed
-- once at batch-creation time from that target's own perspective (e.g.
-- Compulsion's field lists the target's own hand -- no candidate list
-- needs to be embedded, the client already renders an unfiltered
-- hand_card field from the target's own GET /games/state response the
-- same way it does everywhere else). answer is filled in once the target
-- responds. ON DELETE RESTRICT on target_game_player_id for the same
-- reason as initiating_game_player_id above: nothing can delete a seated
-- game_players row today (no "leave game" feature exists), but if one is
-- ever added it must not be allowed to silently vanish an outstanding
-- decision and unfreeze the round with the original card's effect never
-- actually resolved.
CREATE TABLE IF NOT EXISTS game_pending_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    step_index TINYINT UNSIGNED NOT NULL,
    target_game_player_id INT UNSIGNED NOT NULL,
    decision_type VARCHAR(40) NOT NULL,
    field JSON NOT NULL,
    answer JSON DEFAULT NULL,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pending_decisions_step (batch_id, step_index),
    KEY idx_pending_decisions_target_open (target_game_player_id, resolved_at),
    CONSTRAINT fk_pending_decisions_batch FOREIGN KEY (batch_id) REFERENCES game_pending_decision_batches (id) ON DELETE CASCADE,
    CONSTRAINT fk_pending_decisions_target FOREIGN KEY (target_game_player_id) REFERENCES game_players (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

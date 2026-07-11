-- Duel mode gives each player their own complete deck (built by the same
-- deck-building rules as a normal single-player deck) rather than splitting
-- one shared pool, so the same catalog card can now legitimately exist
-- twice in one game -- once per player. game_cards.id (the surrogate PK
-- that already existed solely to resolve suppression_source_game_card_id's
-- self-reference) becomes every card's real per-game identity instead of
-- card_id (the catalog id), which uq_game_cards_card previously enforced
-- as unique per game. See BoardState::catalogRow()/$catalogCardIdFor.
ALTER TABLE game_cards
    DROP INDEX uq_game_cards_card,
    ADD KEY idx_game_cards_card (card_id);

-- copied_card_id stores the copied mood's own per-game instance id (the
-- 'copy_card_id' choice names an in-play card the same way every other
-- choice does -- see BoardState::effectiveCardId()), not a catalog id as
-- originally modeled. Repointed self-referentially, same pattern as
-- suppression_source_game_card_id, and widened from SMALLINT to match
-- game_cards.id's own INT UNSIGNED width. Split into three separate
-- statements (rather than one combined ALTER): DROP FOREIGN KEY doesn't
-- drop its supporting index, so re-adding a same-named constraint in the
-- same statement collides with that leftover index (errno 121); MODIFY
-- COLUMN also can't run while the old FK still references the column, and
-- the new FK can't be added until the column's width already matches
-- game_cards.id's.
ALTER TABLE game_cards
    DROP FOREIGN KEY fk_game_cards_copied_card;
ALTER TABLE game_cards
    MODIFY COLUMN copied_card_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE game_cards
    ADD CONSTRAINT fk_game_cards_copied_card FOREIGN KEY (copied_card_id) REFERENCES game_cards (id) ON DELETE SET NULL;

-- played_card_id/card_id below store whatever $cardId GameService had in
-- hand at the time, now always a game_cards.id -- repointed + widened from
-- SMALLINT UNSIGNED (max 65535, far too narrow for game_cards.id's range
-- across every game on the site) to match. Same three-statement split as
-- above, for the same reasons.
ALTER TABLE game_pending_decision_batches
    DROP FOREIGN KEY fk_pending_batches_card;
ALTER TABLE game_pending_decision_batches
    MODIFY COLUMN played_card_id INT UNSIGNED NOT NULL;
ALTER TABLE game_pending_decision_batches
    ADD CONSTRAINT fk_pending_batches_card FOREIGN KEY (played_card_id) REFERENCES game_cards (id) ON DELETE CASCADE;

ALTER TABLE game_events
    DROP FOREIGN KEY fk_game_events_card;
ALTER TABLE game_events
    MODIFY COLUMN card_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE game_events
    ADD CONSTRAINT fk_game_events_card FOREIGN KEY (card_id) REFERENCES game_cards (id) ON DELETE CASCADE;

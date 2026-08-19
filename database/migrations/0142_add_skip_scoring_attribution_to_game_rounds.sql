-- Follow-up to 0141: that migration fixed the actual "no scoring this
-- round" / "who goes first" mechanism surviving Awe leaving play, but
-- GameService::scoringEffectEntries() -- the "How scoring will be
-- affected" section at the top of the game display -- still built its
-- own Awe entry by scanning moodsInPlay() for the OLD per-card
-- 'skipScoringThisRound' tag, which AweEffect no longer sets. The entry
-- silently stopped appearing at all, the same failure mode 0141 already
-- fixed for the underlying mechanism itself.
-- These two columns let that entry be attributed to Awe's own card name
-- and the player who played it, the same way the entry always could
-- before, but read straight from round-level state instead of a
-- moodsInPlay() scan -- so it now stays visible for exactly as long as
-- the underlying effect does, including for the rest of the round after
-- Awe itself is gone.
ALTER TABLE game_rounds
    ADD COLUMN skip_scoring_source_card_id INT UNSIGNED DEFAULT NULL AFTER skip_scoring_first_player_game_player_id,
    ADD COLUMN skip_scoring_owner_game_player_id INT UNSIGNED DEFAULT NULL AFTER skip_scoring_source_card_id;

ALTER TABLE game_rounds
    ADD CONSTRAINT fk_game_rounds_skip_scoring_source_card FOREIGN KEY (skip_scoring_source_card_id) REFERENCES game_cards (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_game_rounds_skip_scoring_owner FOREIGN KEY (skip_scoring_owner_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL;

UPDATE schema_version SET version = '1.27.2' WHERE id = 1;

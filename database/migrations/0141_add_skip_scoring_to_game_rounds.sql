-- Awe's own "after playing this mood, there is no scoring this round; you
-- choose which player goes first next round" was previously tracked as
-- effect_state tagged on Awe's own card (GameService::hasSkipScoringMarker()/
-- BoardState::firstPlayerOverride() both scanned moodsInPlay() for it). But
-- Awe's ability triggers *after playing*, not *while in play* -- unlike
-- Honor's own similarly-named but genuinely ongoing "while in play, the
-- chosen player goes first" ability -- so the choice is already fully
-- locked in the instant Awe resolves, regardless of what happens to Awe
-- itself afterward. If Awe left play (stolen, discarded, etc.) before the
-- round it was played in actually finished scoring, its own tag vanished
-- along with it (the same way any other card's effect_state does the
-- moment it leaves moodsInPlay), silently losing both the skipped scoring
-- and the chosen first player.
-- These two new columns move that state to the round itself, the same
-- "has to be persisted between requests, and isn't conditional on staying
-- in play" reasoning migration 0006 already used for current_turn_game_player_id/
-- plays_remaining -- a round can span many requests before it scores, so
-- there's no in-memory place to remember this otherwise.
ALTER TABLE game_rounds
    ADD COLUMN skip_scoring TINYINT(1) NOT NULL DEFAULT 0 AFTER plays_remaining,
    ADD COLUMN skip_scoring_first_player_game_player_id INT UNSIGNED DEFAULT NULL AFTER skip_scoring;

ALTER TABLE game_rounds
    ADD CONSTRAINT fk_game_rounds_skip_scoring_first_player FOREIGN KEY (skip_scoring_first_player_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL;

UPDATE schema_version SET version = '1.27.1' WHERE id = 1;

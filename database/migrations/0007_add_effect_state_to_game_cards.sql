-- Adds storage for a mood's per-card effect state: modal choices (e.g.
-- Imagination's chosen color) and one-time value overrides (e.g. Dignity's
-- "value becomes 5"), mirroring MoodInPlay::$effectState in the in-memory
-- rules engine (see src/Rules/MoodInPlay.php). This was missed when
-- game_cards was first created in 0004 -- everything else needed to
-- reconstruct a MoodInPlay already had a column, but this one didn't.
ALTER TABLE game_cards
    ADD COLUMN effect_state JSON DEFAULT NULL AFTER suppression_source_game_card_id;

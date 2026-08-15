-- Fixes a real bug, caught live: with Scorn already in play, playing
-- Shame (discarding a white card) suppressed an opponent's Loyalty
-- 'while_source_in_play' (Shame's own "for as long as you have this
-- mood"), and then Scorn's own reaction to that same play let the owner
-- ALSO suppress that Loyalty 'end_of_round'. game_cards' old single-slot
-- suppression columns (is_suppressed/suppression_expiry/
-- suppression_source_game_card_id) can only remember one suppression at a
-- time, so the second suppress() call silently clobbered the first --
-- when the round ended, clearEndOfRoundSuppressions() saw Loyalty's own
-- (Scorn-overwritten) 'end_of_round' expiry and lifted it entirely,
-- forgetting Shame's still-very-much-active claim on it.
--
-- Replaces the three single-slot columns with one `suppressions` JSON
-- column holding a list of {"expiry", "sourceCardId"} entries -- see
-- MoodInPlay's own docblock (php-app/src/Rules/MoodInPlay.php) for why a
-- mood can genuinely be suppressed by more than one source at once, each
-- expiring independently. NULL means "no suppressions", the same
-- NULL-means-empty convention `effect_state` already uses (see
-- BoardStateRepository::save()).
ALTER TABLE game_cards
    DROP FOREIGN KEY fk_game_cards_suppression_source;
ALTER TABLE game_cards
    DROP COLUMN is_suppressed,
    DROP COLUMN suppression_expiry,
    DROP COLUMN suppression_source_game_card_id,
    ADD COLUMN suppressions JSON DEFAULT NULL AFTER copied_card_id;

UPDATE schema_version SET version = '1.25.3' WHERE id = 1;

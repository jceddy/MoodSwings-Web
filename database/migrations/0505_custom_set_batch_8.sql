-- Community set 'Emotional Baggage' (XEMB), batch 8: Schadenfreude. game_rounds.turn_hand_discarders remembers who has discarded
-- a card from their hand during the current turn (BoardState::playersWhoDiscardedFromHandThisTurn()).

ALTER TABLE game_rounds ADD COLUMN turn_hand_discarders LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL CHECK (json_valid(turn_hand_discarders));

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (213, 'Schadenfreude', 'schadenfreude', 'black', 'common', 3, 6, 0, 0, 1, 'After playing this mood — If another player discarded a card this turn, this mood''s value becomes 6.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (213, (SELECT id FROM sets WHERE code = 'XEMB'), 79);

UPDATE schema_version SET version = '1.64.9' WHERE id = 1;

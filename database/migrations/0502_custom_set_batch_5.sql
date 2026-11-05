-- Community set 'Emotional Baggage' (XEMB), batch 5: the extra scoring cards (ScoringBonusEffect).

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (240, 'Fulfillment', 'fulfillment', 'green', 'mythic', 1, NULL, 0, 1, 0, 'While in play — While scoring, score each of your moods with a value of 6 or more an extra time.', 'XEMB'),
    (241, 'Gratitude', 'gratitude', 'green', 'uncommon', 0, NULL, 0, 1, 0, 'While in play — While scoring, score each of your moods with a value of 2 or less an extra time.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (240, (SELECT id FROM sets WHERE code = 'XEMB'), 111),
    (241, (SELECT id FROM sets WHERE code = 'XEMB'), 112);

UPDATE schema_version SET version = '1.64.6' WHERE id = 1;

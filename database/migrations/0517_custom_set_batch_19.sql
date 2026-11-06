-- Community set 'Emotional Baggage' (XEMB), batch 19: the repeat-effect cards (Optimism, Wistfulness, Reinvigoration, Admiration).

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (246, 'Optimism', 'optimism', 'green', 'uncommon', 3, NULL, 0, 0, 1, 'After playing this mood — You may choose one of your other moods. If you do, that mood''s after-playing effect happens an additional time.', 'XEMB'),
    (260, 'Wistfulness', 'wistfulness', 'green', 'mythic', 1, NULL, 0, 1, 1, 'After playing this mood — You may play an additional mood this turn from the discard pile. While in play — Each time you play another mood from the discard pile, you may have that mood''s after-playing effect happen an additional time.', 'XEMB'),
    (249, 'Reinvigoration', 'reinvigoration', 'green', 'mythic', 0, NULL, 0, 0, 1, 'After playing this mood — Put all your other moods into the discard pile (moods are cards in play). Then you may play up to that many additional moods this turn from the discard pile. You can''t play any moods this turn except moods played this way.', 'XEMB'),
    (171, 'Admiration', 'admiration', 'blue', 'rare', 1, NULL, 0, 0, 1, 'After playing this mood — You may choose a mood in the discard pile. If you do, that mood''s after-playing effect happens as though you had just played it.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (246, (SELECT id FROM sets WHERE code = 'XEMB'), 119),
    (260, (SELECT id FROM sets WHERE code = 'XEMB'), 133),
    (249, (SELECT id FROM sets WHERE code = 'XEMB'), 122),
    (171, (SELECT id FROM sets WHERE code = 'XEMB'), 34);

UPDATE schema_version SET version = '1.64.21' WHERE id = 1;

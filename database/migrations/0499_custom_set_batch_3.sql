-- Community set 'Emotional Baggage' (XEMB), batch 3: the static value modifiers (ValueModifyingEffect), Competitiveness, and Vitality.

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (218, 'Ardor', 'ardor', 'red', 'mythic', 0, NULL, 0, 1, 0, 'While in play — Each of your red moods has its value increased by the number of red moods you have.', 'XEMB'),
    (220, 'Competitiveness', 'competitiveness', 'red', 'mythic', 0, NULL, 0, 1, 0, 'While in play — This mood''s value is 1 more than the highest value among your opponents'' moods that don''t have this ability. (If there are no such moods, this mood''s value is 1.)', 'XEMB'),
    (157, 'Humility', 'humility', 'white', 'uncommon', 3, NULL, 0, 1, 0, 'While in play — Each of your moods with a 0, 1, or 2 in its top right corner has its value increased by 1.', 'XEMB'),
    (243, 'Kinship', 'kinship', 'green', 'rare', 1, NULL, 0, 1, 0, 'While in play — Each of your moods that shares a color with another of your moods has its value increased by 1.', 'XEMB'),
    (163, 'Orderliness', 'orderliness', 'white', 'mythic', 2, NULL, 0, 1, 1, 'After playing this mood — Choose odd numbers or even numbers. While in play — Each of your other moods with a number of the chosen type in its top right corner has its value increased by 1. (0 is even.)', 'XEMB'),
    (235, 'Verve', 'verve', 'red', 'rare', 1, NULL, 0, 1, 0, 'While in play — Each of your red moods that you played this round has its value increased by 2.', 'XEMB'),
    (256, 'Vitality', 'vitality', 'green', 'rare', 1, NULL, 0, 1, 0, 'While in play — At the start of each of your turns, choose a color. Until the end of this round, each of your moods of that color has its value increased by 2.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (218, (SELECT id FROM sets WHERE code = 'XEMB'), 85),
    (220, (SELECT id FROM sets WHERE code = 'XEMB'), 87),
    (157, (SELECT id FROM sets WHERE code = 'XEMB'), 19),
    (243, (SELECT id FROM sets WHERE code = 'XEMB'), 115),
    (163, (SELECT id FROM sets WHERE code = 'XEMB'), 25),
    (235, (SELECT id FROM sets WHERE code = 'XEMB'), 104),
    (256, (SELECT id FROM sets WHERE code = 'XEMB'), 129);

UPDATE schema_version SET version = '1.64.3' WHERE id = 1;

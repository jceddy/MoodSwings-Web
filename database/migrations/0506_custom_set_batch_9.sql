-- Community set 'Emotional Baggage' (XEMB), batch 9: the colorless cards. A card can now have no color at all.

ALTER TABLE cards MODIFY color ENUM('white', 'blue', 'black', 'red', 'green', 'colorless') NOT NULL;

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (145, 'Reverence', 'reverence', 'colorless', 'common', 5, NULL, 1, 0, 0, 'You can only play this card if you have a white mood.', 'XEMB'),
    (140, 'Detachment', 'detachment', 'colorless', 'common', 5, NULL, 1, 0, 0, 'You can only play this card if you have a blue mood.', 'XEMB'),
    (144, 'Resentment', 'resentment', 'colorless', 'common', 5, NULL, 1, 0, 0, 'You can only play this card if you have a black mood.', 'XEMB'),
    (142, 'Impatience', 'impatience', 'colorless', 'common', 5, NULL, 1, 0, 0, 'You can only play this card if you have a red mood.', 'XEMB'),
    (139, 'Contentment', 'contentment', 'colorless', 'common', 5, NULL, 1, 0, 0, 'You can only play this card if you have a green mood.', 'XEMB'),
    (141, 'Emptiness', 'emptiness', 'colorless', 'uncommon', 1, NULL, 0, 1, 0, 'While in play — This mood''s value increases by 1 for each color among all moods. (Colorless moods have no color.)', 'XEMB'),
    (143, 'Neutrality', 'neutrality', 'colorless', 'uncommon', 1, NULL, 0, 1, 0, 'While in play — This mood''s value increases by 2 for each other mood with no color. (Moods are cards in play.)', 'XEMB'),
    (146, 'Unity', 'unity', 'colorless', 'mythic', 3, NULL, 0, 1, 0, 'While in play — After scoring, if you have five or more moods that share a color, you win the round. (Moods are cards in play.)', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (145, (SELECT id FROM sets WHERE code = 'XEMB'), 7),
    (140, (SELECT id FROM sets WHERE code = 'XEMB'), 2),
    (144, (SELECT id FROM sets WHERE code = 'XEMB'), 6),
    (142, (SELECT id FROM sets WHERE code = 'XEMB'), 4),
    (139, (SELECT id FROM sets WHERE code = 'XEMB'), 1),
    (141, (SELECT id FROM sets WHERE code = 'XEMB'), 3),
    (143, (SELECT id FROM sets WHERE code = 'XEMB'), 5),
    (146, (SELECT id FROM sets WHERE code = 'XEMB'), 8);

UPDATE schema_version SET version = '1.64.10' WHERE id = 1;

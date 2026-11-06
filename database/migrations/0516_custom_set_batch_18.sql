-- Community set 'Emotional Baggage' (XEMB), batch 18: the draw and discard trigger cards (Despair, Opportunism, Sentimentality, Rancor).

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (198, 'Despair', 'despair', 'black', 'rare', 2, NULL, 0, 1, 0, 'While in play — At the end of each round you lose, you draw an additional card.', 'XEMB'),
    (210, 'Opportunism', 'opportunism', 'black', 'rare', 1, NULL, 0, 1, 0, 'While in play — Each time an opponent discards a card from their hand, you put that card into your hand.', 'XEMB'),
    (214, 'Sentimentality', 'sentimentality', 'black', 'uncommon', 1, NULL, 0, 1, 0, 'While in play — Each time one of your other moods is put into the discard pile, you draw a card. (Moods are cards in play.)', 'XEMB'),
    (230, 'Rancor', 'rancor', 'red', 'uncommon', 2, NULL, 0, 1, 0, 'While in play — Each time an opponent''s effect puts one or more of your other moods into the discard pile (moods are cards in play), a random one of that player''s moods with a value of 3 or less is put into the discard pile.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (198, (SELECT id FROM sets WHERE code = 'XEMB'), 63),
    (210, (SELECT id FROM sets WHERE code = 'XEMB'), 76),
    (214, (SELECT id FROM sets WHERE code = 'XEMB'), 80),
    (230, (SELECT id FROM sets WHERE code = 'XEMB'), 98);

UPDATE schema_version SET version = '1.64.20' WHERE id = 1;

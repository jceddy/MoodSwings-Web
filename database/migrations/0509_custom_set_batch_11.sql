-- Community set 'Emotional Baggage' (XEMB), batch 11: the draw/cycle cards (Rapport, Longing, Premonition, Resignation).

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (186, 'Rapport', 'rapport', 'blue', 'common', 3, NULL, 0, 0, 1, 'After playing this mood — You may put a card from your hand on the bottom of the deck. If you do, draw a card. If there are two or more white and/or black moods, you may do this a second time.', 'XEMB'),
    (205, 'Longing', 'longing', 'black', 'uncommon', 3, NULL, 0, 0, 1, 'After playing this mood — You may discard a card from your hand. If you do, put up to two cards from the discard pile into your hand.', 'XEMB'),
    (184, 'Premonition', 'premonition', 'blue', 'mythic', 0, NULL, 0, 0, 1, 'After playing this mood — Draw up to three cards, then put that many cards from your hand on the bottom of the deck. You may play an additional mood this turn.', 'XEMB'),
    (189, 'Resignation', 'resignation', 'blue', 'common', 6, NULL, 1, 0, 0, 'To play this card — Put a card from your hand on the bottom of the deck. (If you can''t do that, you can''t play this card.)', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (186, (SELECT id FROM sets WHERE code = 'XEMB'), 51),
    (205, (SELECT id FROM sets WHERE code = 'XEMB'), 71),
    (184, (SELECT id FROM sets WHERE code = 'XEMB'), 49),
    (189, (SELECT id FROM sets WHERE code = 'XEMB'), 54);

UPDATE schema_version SET version = '1.64.13' WHERE id = 1;

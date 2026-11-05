-- Community set 'Emotional Baggage' (XEMB), batch 12: the removal cards (Heartlessness, Vengeance, Righteousness, Vindictiveness, Malevolence, Dejection).

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (203, 'Heartlessness', 'heartlessness', 'black', 'uncommon', 3, NULL, 0, 0, 1, 'After playing this mood — Each player with three or more moods puts one of their moods into the discard pile. (Moods are cards in play.)', 'XEMB'),
    (215, 'Vengeance', 'vengeance', 'black', 'uncommon', 3, NULL, 0, 0, 1, 'After playing this mood — Choose an opponent. That player puts one of their highest value moods into the discard pile.', 'XEMB'),
    (233, 'Righteousness', 'righteousness', 'red', 'uncommon', 3, NULL, 0, 0, 1, 'After playing this mood — Each player with the highest total value among their moods puts one of their highest value moods into the discard pile.', 'XEMB'),
    (236, 'Vindictiveness', 'vindictiveness', 'red', 'uncommon', 3, NULL, 0, 0, 1, 'After playing this mood — You may put one of your other moods into the discard pile (moods are cards in play). If you do, you may put a mood with a lower value than that mood''s into the discard pile.', 'XEMB'),
    (206, 'Malevolence', 'malevolence', 'black', 'common', 2, NULL, 0, 0, 1, 'After playing this mood — Choose odd or even. Choose up to two players. For each chosen player, put one of their moods with a number of the chosen type in its top right corner into the discard pile. (0 is even.)', 'XEMB'),
    (197, 'Dejection', 'dejection', 'black', 'common', 5, NULL, 0, 0, 1, 'After playing this mood — Each player discards a card from their hand.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (203, (SELECT id FROM sets WHERE code = 'XEMB'), 69),
    (215, (SELECT id FROM sets WHERE code = 'XEMB'), 82),
    (233, (SELECT id FROM sets WHERE code = 'XEMB'), 101),
    (236, (SELECT id FROM sets WHERE code = 'XEMB'), 105),
    (206, (SELECT id FROM sets WHERE code = 'XEMB'), 72),
    (197, (SELECT id FROM sets WHERE code = 'XEMB'), 62);

UPDATE schema_version SET version = '1.64.14' WHERE id = 1;

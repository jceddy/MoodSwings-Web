-- Community set 'Emotional Baggage' (XEMB), batch 20: the last four cards (Prudence, Reckoning, Shrewdness, Grudge).

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (185, 'Prudence', 'prudence', 'blue', 'common', 3, NULL, 0, 0, 1, 'After playing this mood — Look at the top two cards of the deck. Put any number of them on the bottom of the deck and the rest back on top in any order.', 'XEMB'),
    (232, 'Reckoning', 'reckoning', 'red', 'rare', 1, NULL, 0, 0, 1, 'After playing this mood — You may have each player choose one of their moods. If you do, put all moods that weren''t chosen into the discard pile.', 'XEMB'),
    (192, 'Shrewdness', 'shrewdness', 'blue', 'rare', 1, NULL, 0, 0, 1, 'After playing this mood — You may choose one of your other moods and one of an opponent''s moods with a value less than or equal to that mood''s. If you do, you and that opponent swap them.', 'XEMB'),
    (156, 'Grudge', 'grudge', 'white', 'mythic', 0, NULL, 0, 1, 1, 'After playing this mood — Choose an opponent. While in play — During each of your turns (including the turn you play this mood), you may play an additional mood for each round the chosen player has won. While in play — If this mood would leave play, put it on the bottom of the deck instead. (Leaving play includes being put into the discard pile or into a player''s hand.)', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (185, (SELECT id FROM sets WHERE code = 'XEMB'), 50),
    (232, (SELECT id FROM sets WHERE code = 'XEMB'), 100),
    (192, (SELECT id FROM sets WHERE code = 'XEMB'), 57),
    (156, (SELECT id FROM sets WHERE code = 'XEMB'), 18);

UPDATE schema_version SET version = '1.64.22' WHERE id = 1;

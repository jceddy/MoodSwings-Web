-- Community set 'Emotional Baggage' (XEMB), batch 10: the extra-play cards and the "each time you play another mood" reactions.

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (154, 'Familiarity', 'familiarity', 'white', 'common', 2, NULL, 0, 0, 1, 'After playing this mood — You may play an additional mood this turn if it has the same number in its top right corner as one of your moods.', 'XEMB'),
    (181, 'Impulse', 'impulse', 'blue', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood — You may play an additional mood this turn. If you do, after scoring, put this mood into your hand if it''s still in play.', 'XEMB'),
    (257, 'Wariness', 'wariness', 'green', 'common', 4, NULL, 0, 0, 1, 'After playing this mood — If there are two or more blue and/or black moods, you may give a card from your hand to another player. If you do, you may play an additional mood this turn.', 'XEMB'),
    (217, 'Adrenaline', 'adrenaline', 'red', 'rare', 0, NULL, 0, 1, 0, 'While in play — The first time you play a red mood each turn, you may play an additional mood this turn.', 'XEMB'),
    (147, 'Affinity', 'affinity', 'white', 'rare', 1, NULL, 0, 1, 0, 'While in play — The first time each turn you play a mood with the same number in its top right corner as another of your moods, you may play an additional mood this turn.', 'XEMB'),
    (222, 'Dominance', 'dominance', 'red', 'mythic', 2, NULL, 0, 1, 0, 'While in play — Each time you play another red mood, you may put one of your opponents'' moods with a value lower than that mood''s into the discard pile.', 'XEMB'),
    (187, 'Reflection', 'reflection', 'blue', 'rare', 1, NULL, 0, 1, 0, 'While in play — Each time you play another mood, you may discard a card from your hand. If you do, draw a card.', 'XEMB'),
    (190, 'Reverie', 'reverie', 'blue', 'mythic', 2, NULL, 0, 1, 1, 'After playing this mood — You may put a card from the discard pile on the bottom of the deck. If you do, draw a card. While in play — Each time you play another mood, you may do this.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (154, (SELECT id FROM sets WHERE code = 'XEMB'), 16),
    (181, (SELECT id FROM sets WHERE code = 'XEMB'), 45),
    (257, (SELECT id FROM sets WHERE code = 'XEMB'), 130),
    (217, (SELECT id FROM sets WHERE code = 'XEMB'), 84),
    (147, (SELECT id FROM sets WHERE code = 'XEMB'), 9),
    (222, (SELECT id FROM sets WHERE code = 'XEMB'), 90),
    (187, (SELECT id FROM sets WHERE code = 'XEMB'), 52),
    (190, (SELECT id FROM sets WHERE code = 'XEMB'), 55);

UPDATE schema_version SET version = '1.64.12' WHERE id = 1;

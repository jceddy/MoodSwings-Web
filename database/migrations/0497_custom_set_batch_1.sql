-- Community set 'Emotional Baggage' (XEMB), batch 1: the reprints of original cards, plus the first 32 new cards
-- whose effects need no new engine hooks (conditional values, extra-play grants, bulk and targeted moves).

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (172, 'Anticipation', 'anticipation', 'blue', 'common', 3, 6, 0, 1, 0, 'While in play — This mood''s value is 6 if you have four or more cards in your hand.', 'XEMB'),
    (196, 'Composure', 'composure', 'black', 'uncommon', 2, 6, 0, 1, 0, 'While in play — This mood''s value is 6 if you have more cards in your hand than each other player.', 'XEMB'),
    (152, 'Equanimity', 'equanimity', 'white', 'uncommon', 3, 6, 0, 1, 0, 'While in play — This mood''s value is 6 if there are four or more moods with even numbers in their top right corners. (0 is even.)', 'XEMB'),
    (223, 'Exuberance', 'exuberance', 'red', 'common', 3, 6, 0, 1, 0, 'While in play — This mood''s value is 6 if you have two or more other red moods.', 'XEMB'),
    (227, 'Hellbent', 'hellbent', 'red', 'uncommon', 2, 7, 0, 1, 0, 'While in play — This mood''s value is 7 if you have no cards in your hand.', 'XEMB'),
    (248, 'Possessiveness', 'possessiveness', 'green', 'uncommon', 0, 6, 0, 1, 0, 'While in play — This mood''s value is 6 if there are five or more cards in the discard pile.', 'XEMB'),
    (234, 'Ruthlessness', 'ruthlessness', 'red', 'uncommon', 3, 5, 0, 1, 0, 'While in play — This mood''s value is 5 if any opponent has no cards in their hand.', 'XEMB'),
    (169, 'Temperance', 'temperance', 'white', 'common', 3, 5, 0, 1, 0, 'While in play — This mood''s value is 5 if you have fewer cards in hand than each other player.', 'XEMB'),
    (168, 'Solidarity', 'solidarity', 'white', 'uncommon', 2, NULL, 0, 1, 0, 'While in play — This mood''s value increases by 1 for each other mood with a 2 in its top right corner.', 'XEMB'),
    (150, 'Confidence', 'confidence', 'white', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood — You may play an additional mood this turn if it has a 4, 5, or 6 in its top right corner.', 'XEMB'),
    (162, 'Modesty', 'modesty', 'white', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood — You may play an additional mood this turn if it has a 0, 1, 2, or 3 in its top right corner.', 'XEMB'),
    (253, 'Thrift', 'thrift', 'green', 'uncommon', 3, NULL, 0, 0, 1, 'After playing this mood — You may play an additional mood this turn from the discard pile if it has a 0, 1, 2, or 3 in its top right corner.', 'XEMB'),
    (177, 'Desperation', 'desperation', 'blue', 'uncommon', 3, NULL, 0, 0, 1, 'After playing this mood — You may play an additional mood this turn if you have fewer cards in hand than each other player.', 'XEMB'),
    (148, 'Camaraderie', 'camaraderie', 'white', 'common', 2, NULL, 0, 0, 1, 'After playing this mood — If there are two or more green and/or blue moods, you may play an additional mood this turn.', 'XEMB'),
    (224, 'Fervor', 'fervor', 'red', 'common', 3, NULL, 0, 0, 1, 'After playing this mood — If there are two or more black and/or green moods, you may play an additional mood this turn. If you do, after scoring, put that mood into the discard pile if it''s still in play.', 'XEMB'),
    (231, 'Rashness', 'rashness', 'red', 'common', 0, NULL, 0, 0, 1, 'After playing this mood — You may put one of your opponents'' moods with a value of 1 or less into the discard pile. You may play an additional mood this turn.', 'XEMB'),
    (226, 'Frenzy', 'frenzy', 'red', 'common', 6, NULL, 0, 0, 1, 'After playing this mood — After scoring this round, put this mood into the discard pile.', 'XEMB'),
    (159, 'Largesse', 'largesse', 'white', 'common', 5, NULL, 0, 0, 1, 'After playing this mood — Each other player draws a card.', 'XEMB'),
    (219, 'Belligerence', 'belligerence', 'red', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood — You may put all of your opponents'' moods with a value of 2 or less into the discard pile.', 'XEMB'),
    (174, 'Chagrin', 'chagrin', 'blue', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood — You may put all other moods with a value of 4 or more into their players'' hands.', 'XEMB'),
    (194, 'Atonement', 'atonement', 'black', 'uncommon', 1, NULL, 0, 0, 1, 'After playing this mood — Choose a color. Put all other moods of the chosen color into the discard pile.', 'XEMB'),
    (229, 'Intuition', 'intuition', 'red', 'uncommon', 1, NULL, 0, 0, 1, 'After playing this mood — Choose a number. Put all other moods with the chosen number in their top right corners into the discard pile.', 'XEMB'),
    (158, 'Impartiality', 'impartiality', 'white', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood — Choose odd or even. Suppress all other moods with a number of the chosen type in their top right corners until the end of this round. (0 is even.)', 'XEMB'),
    (188, 'Reminiscence', 'reminiscence', 'blue', 'common', 3, NULL, 0, 0, 1, 'After playing this mood — You may put a card from the discard pile on the bottom of the deck. If you do, draw a card.', 'XEMB'),
    (212, 'Remorse', 'remorse', 'black', 'common', 0, NULL, 0, 0, 1, 'After playing this mood — You may put a card from the discard pile on the bottom of the deck. If you do, draw a card. You may play an additional mood this turn.', 'XEMB'),
    (161, 'Levity', 'levity', 'white', 'common', 0, NULL, 0, 0, 1, 'After playing this mood — You may put this mood on the bottom of the deck. If you do, draw a card. You may play an additional mood this turn. (Moods are cards in play.)', 'XEMB'),
    (178, 'Distrust', 'distrust', 'blue', 'common', 3, NULL, 0, 0, 1, 'After playing this mood — If there are two or more red and/or green moods, you may put one of them into its player''s hand.', 'XEMB'),
    (199, 'Disdain', 'disdain', 'black', 'common', 2, NULL, 0, 0, 1, 'After playing this mood — If there are two or more white and/or green moods, you may put one of them into the discard pile.', 'XEMB'),
    (228, 'Indignation', 'indignation', 'red', 'common', 3, NULL, 0, 0, 1, 'After playing this mood — If there are two or more white and/or blue moods, you may put one of them with a value of 4 or less into the discard pile.', 'XEMB'),
    (170, 'Vigilance', 'vigilance', 'white', 'common', 3, NULL, 0, 0, 1, 'After playing this mood — If there are two or more black and/or red moods, you may suppress one of them for as long as you have this mood.', 'XEMB'),
    (258, 'Warmth', 'warmth', 'green', 'common', 3, NULL, 0, 0, 1, 'After playing this mood — If there are two or more red and/or white moods, you may put a card from the discard pile into your hand.', 'XEMB'),
    (216, 'Yearning', 'yearning', 'black', 'common', 3, NULL, 0, 0, 1, 'After playing this mood — If there are two or more blue and/or red moods, you may discard a card from your hand. If you do, put a card from the discard pile into your hand.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (172, (SELECT id FROM sets WHERE code = 'XEMB'), 35),
    (196, (SELECT id FROM sets WHERE code = 'XEMB'), 61),
    (152, (SELECT id FROM sets WHERE code = 'XEMB'), 14),
    (223, (SELECT id FROM sets WHERE code = 'XEMB'), 91),
    (227, (SELECT id FROM sets WHERE code = 'XEMB'), 95),
    (248, (SELECT id FROM sets WHERE code = 'XEMB'), 121),
    (234, (SELECT id FROM sets WHERE code = 'XEMB'), 102),
    (169, (SELECT id FROM sets WHERE code = 'XEMB'), 32),
    (168, (SELECT id FROM sets WHERE code = 'XEMB'), 31),
    (150, (SELECT id FROM sets WHERE code = 'XEMB'), 12),
    (162, (SELECT id FROM sets WHERE code = 'XEMB'), 24),
    (253, (SELECT id FROM sets WHERE code = 'XEMB'), 126),
    (177, (SELECT id FROM sets WHERE code = 'XEMB'), 40),
    (148, (SELECT id FROM sets WHERE code = 'XEMB'), 10),
    (224, (SELECT id FROM sets WHERE code = 'XEMB'), 92),
    (231, (SELECT id FROM sets WHERE code = 'XEMB'), 99),
    (226, (SELECT id FROM sets WHERE code = 'XEMB'), 94),
    (159, (SELECT id FROM sets WHERE code = 'XEMB'), 21),
    (219, (SELECT id FROM sets WHERE code = 'XEMB'), 86),
    (174, (SELECT id FROM sets WHERE code = 'XEMB'), 37),
    (194, (SELECT id FROM sets WHERE code = 'XEMB'), 59),
    (229, (SELECT id FROM sets WHERE code = 'XEMB'), 97),
    (158, (SELECT id FROM sets WHERE code = 'XEMB'), 20),
    (188, (SELECT id FROM sets WHERE code = 'XEMB'), 53),
    (212, (SELECT id FROM sets WHERE code = 'XEMB'), 78),
    (161, (SELECT id FROM sets WHERE code = 'XEMB'), 23),
    (178, (SELECT id FROM sets WHERE code = 'XEMB'), 41),
    (199, (SELECT id FROM sets WHERE code = 'XEMB'), 64),
    (228, (SELECT id FROM sets WHERE code = 'XEMB'), 96),
    (170, (SELECT id FROM sets WHERE code = 'XEMB'), 33),
    (258, (SELECT id FROM sets WHERE code = 'XEMB'), 131),
    (216, (SELECT id FROM sets WHERE code = 'XEMB'), 83);

-- Reprints: the same card rows as the original set, also listed in XEMB.
INSERT INTO card_sets (card_id, set_id, collector_number)
SELECT c.id, (SELECT id FROM sets WHERE code = 'XEMB'), r.num
FROM (SELECT 'compulsion' AS k, 88 AS num UNION ALL SELECT 'determination', 110 UNION ALL SELECT 'fear', 43 UNION ALL SELECT 'hate', 67 UNION ALL SELECT 'joy', 114 UNION ALL SELECT 'nostalgia', 117 UNION ALL SELECT 'pacifism', 26 UNION ALL SELECT 'panic', 48 UNION ALL SELECT 'shock', 103 UNION ALL SELECT 'suspicion', 81 UNION ALL SELECT 'zeal', 108) r
JOIN cards c ON c.effect_key = r.k;

UPDATE schema_version SET version = '1.64.1' WHERE id = 1;

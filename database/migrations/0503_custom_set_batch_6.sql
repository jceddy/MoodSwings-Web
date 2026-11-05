-- Community set 'Emotional Baggage' (XEMB), batch 6: Carelessness (deck top to discard) and the hand-reveal cards that need no new decision UI.

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (173, 'Candor', 'candor', 'blue', 'uncommon', 3, NULL, 0, 1, 0, 'While in play — Your opponents play with their hands revealed.', 'XEMB'),
    (239, 'Carelessness', 'carelessness', 'green', 'common', 3, NULL, 0, 0, 1, 'After playing this mood — Put the top two cards of the deck into the discard pile.', 'XEMB'),
    (151, 'Discernment', 'discernment', 'white', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood — Choose odd or even. You may reveal any number of cards from your hand with a number of the chosen type in their top right corners and put them on the bottom of the deck, then draw that many cards. (0 is even.)', 'XEMB'),
    (242, 'Honesty', 'honesty', 'green', 'uncommon', 3, 6, 0, 0, 1, 'After playing this mood — Reveal your hand. If each card in it shares a color with one of your moods, this mood''s value becomes 6.', 'XEMB'),
    (193, 'Turmoil', 'turmoil', 'blue', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood — Choose odd or even and choose a player. That player reveals their hand, puts each card from it with a number of the chosen type in its top right corner on the bottom of the deck, then draws that many cards. (0 is even.)', 'XEMB'),
    (238, 'Whimsy', 'whimsy', 'red', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood — Reveal a random card from your hand. You may play it as an additional mood this turn.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (173, (SELECT id FROM sets WHERE code = 'XEMB'), 36),
    (239, (SELECT id FROM sets WHERE code = 'XEMB'), 109),
    (151, (SELECT id FROM sets WHERE code = 'XEMB'), 13),
    (242, (SELECT id FROM sets WHERE code = 'XEMB'), 113),
    (193, (SELECT id FROM sets WHERE code = 'XEMB'), 58),
    (238, (SELECT id FROM sets WHERE code = 'XEMB'), 107);

UPDATE schema_version SET version = '1.64.7' WHERE id = 1;

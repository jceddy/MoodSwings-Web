-- Community set 'Emotional Baggage' (XEMB), batch 7: the reveal-then-pick cards ('revealed_card' decision field).

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (201, 'Greed', 'greed', 'black', 'rare', 2, NULL, 0, 0, 1, 'After playing this mood — Choose an opponent with one or more cards in their hand. That player reveals their hand. Put a card of your choice from it into your hand, then that player draws a card.', 'XEMB'),
    (209, 'Nosiness', 'nosiness', 'black', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood — Choose an opponent with one or more cards in their hand. That player reveals their hand and discards a card of your choice from it.', 'XEMB'),
    (200, 'Entitlement', 'entitlement', 'black', 'mythic', 0, NULL, 0, 0, 1, 'After playing this mood — Choose an opponent who has one or more cards in their hand and one or more moods. That player may reveal their hand. If they do, choose a card from it and put it into your hand, and you may play it as an additional mood this turn. If they don''t, that player chooses one of their highest value moods and puts it into the discard pile.', 'XEMB'),
    (182, 'Inspiration', 'inspiration', 'blue', 'mythic', 0, NULL, 0, 0, 1, 'After playing this mood — Reveal the top three cards of the deck. Put one of them into your hand and the rest on the bottom of the deck in a random order. You may play that card as an additional mood this turn.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (201, (SELECT id FROM sets WHERE code = 'XEMB'), 66),
    (209, (SELECT id FROM sets WHERE code = 'XEMB'), 75),
    (200, (SELECT id FROM sets WHERE code = 'XEMB'), 65),
    (182, (SELECT id FROM sets WHERE code = 'XEMB'), 46);

UPDATE schema_version SET version = '1.64.8' WHERE id = 1;

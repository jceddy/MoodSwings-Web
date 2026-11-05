-- Community set 'Emotional Baggage' (XEMB), batch 2: the "at the start of each of your turns" moods (TurnStartEffect) and Resilience.
-- game_rounds.turn_start_pending_card_ids remembers which of a turn's start-of-turn triggers still have to fire while the round waits on one's decision.

ALTER TABLE game_rounds ADD COLUMN turn_start_pending_card_ids LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL CHECK (json_valid(turn_start_pending_card_ids));

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (195, 'Brooding', 'brooding', 'black', 'rare', 1, NULL, 0, 1, 0, 'While in play — At the start of each of your turns, you may put a card from the discard pile into your hand. If you do, discard a card from your hand.', 'XEMB'),
    (221, 'Covetousness', 'covetousness', 'red', 'rare', 0, NULL, 0, 1, 0, 'While in play — At the start of each of your turns, you may choose one of your opponents'' moods with a value of 1 or less. It becomes yours.', 'XEMB'),
    (153, 'Fairness', 'fairness', 'white', 'rare', 1, NULL, 0, 1, 0, 'While in play — At the start of each of your turns, suppress one of the highest value moods until the end of this round.', 'XEMB'),
    (225, 'FOMO', 'fomo', 'red', 'rare', 7, NULL, 1, 1, 0, 'To play this card — Discard a card from your hand. (If you can''t do that, you can''t play this card.) While in play — At the start of each of your turns, you may discard a card from your hand. If you don''t, put this mood into the discard pile.', 'XEMB'),
    (204, 'Jealousy', 'jealousy', 'black', 'rare', 0, NULL, 0, 1, 0, 'While in play — At the start of each of your turns, each opponent with more moods than you puts one of their moods into the discard pile. (Moods are cards in play.)', 'XEMB'),
    (207, 'Martyrdom', 'martyrdom', 'black', 'rare', 1, NULL, 0, 1, 0, 'While in play — At the start of each of your turns, you may put one of your other moods into the discard pile (moods are cards in play). If you do, each opponent discards a card from their hand.', 'XEMB'),
    (244, 'Maturity', 'maturity', 'green', 'rare', 2, NULL, 0, 1, 0, 'While in play — At the start of each of your turns, this mood''s value permanently increases by 2.', 'XEMB'),
    (208, 'Neglect', 'neglect', 'black', 'mythic', 0, NULL, 0, 1, 0, 'While in play — At the start of each of your turns, each opponent with no cards in their hand puts one of their moods into the discard pile.', 'XEMB'),
    (250, 'Resilience', 'resilience', 'green', 'rare', 0, NULL, 0, 1, 0, 'While in play — During each of your turns (including the turn you play this mood), you may play an additional mood from the discard pile if it has a 0, 1, 2, or 3 in its top right corner.', 'XEMB'),
    (191, 'Rumination', 'rumination', 'blue', 'rare', 1, NULL, 0, 1, 0, 'While in play — At the start of each of your turns, you may put one of your moods into your hand.', 'XEMB'),
    (237, 'Volatility', 'volatility', 'red', 'rare', 2, NULL, 0, 1, 0, 'While in play — At the start of each of your turns, a random mood with a value of 1 or less is put into the discard pile.', 'XEMB'),
    (259, 'Wastefulness', 'wastefulness', 'green', 'rare', 0, NULL, 0, 1, 0, 'While in play — At the start of each of your turns, you may put the top card of the deck into the discard pile.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (195, (SELECT id FROM sets WHERE code = 'XEMB'), 60),
    (221, (SELECT id FROM sets WHERE code = 'XEMB'), 89),
    (153, (SELECT id FROM sets WHERE code = 'XEMB'), 15),
    (225, (SELECT id FROM sets WHERE code = 'XEMB'), 93),
    (204, (SELECT id FROM sets WHERE code = 'XEMB'), 70),
    (207, (SELECT id FROM sets WHERE code = 'XEMB'), 73),
    (244, (SELECT id FROM sets WHERE code = 'XEMB'), 116),
    (208, (SELECT id FROM sets WHERE code = 'XEMB'), 74),
    (250, (SELECT id FROM sets WHERE code = 'XEMB'), 123),
    (191, (SELECT id FROM sets WHERE code = 'XEMB'), 56),
    (237, (SELECT id FROM sets WHERE code = 'XEMB'), 106),
    (259, (SELECT id FROM sets WHERE code = 'XEMB'), 132);

UPDATE schema_version SET version = '1.64.2' WHERE id = 1;

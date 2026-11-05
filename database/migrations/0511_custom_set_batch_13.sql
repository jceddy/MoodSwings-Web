-- Community set 'Emotional Baggage' (XEMB), batch 13: Grievance, Urgency and Restlessness (the last of the tier-1 cards). game_rounds.round_mood_plays records how many moods each player has played this round (Urgency).

ALTER TABLE game_rounds ADD COLUMN round_mood_plays LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL CHECK (json_valid(round_mood_plays));

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (180, 'Grievance', 'grievance', 'blue', 'rare', 3, NULL, 0, 0, 1, 'After playing this mood — Each opponent with more cards in their hand than you puts a card from their hand on the bottom of the deck.', 'XEMB'),
    (254, 'Urgency', 'urgency', 'green', 'uncommon', 3, 6, 0, 1, 0, 'While in play — This mood''s value is 6 if you played two or more moods this round.', 'XEMB'),
    (252, 'Restlessness', 'restlessness', 'green', 'uncommon', 3, 6, 0, 1, 0, 'While in play — This mood''s value is 6 if there are three or more different values among your moods that don''t have this ability. (Moods are cards in play.)', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (180, (SELECT id FROM sets WHERE code = 'XEMB'), 44),
    (254, (SELECT id FROM sets WHERE code = 'XEMB'), 127),
    (252, (SELECT id FROM sets WHERE code = 'XEMB'), 125);

UPDATE schema_version SET version = '1.64.15' WHERE id = 1;

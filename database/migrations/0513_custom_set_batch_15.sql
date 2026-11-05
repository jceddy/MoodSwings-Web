-- Community set 'Emotional Baggage' (XEMB), batch 15: the "can't choose your moods" cards (Solace, Reassurance, Elusiveness).
-- game_rounds.round_choice_shields: the players whose moods their opponents can't choose until the round ends (Solace).

ALTER TABLE game_rounds ADD COLUMN round_choice_shields LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL CHECK (json_valid(round_choice_shields));

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (167, 'Solace', 'solace', 'white', 'common', 2, NULL, 0, 0, 1, 'After playing this mood — Until the end of this round, your opponents can''t choose your moods.', 'XEMB'),
    (164, 'Reassurance', 'reassurance', 'white', 'uncommon', 2, NULL, 0, 1, 0, 'While in play — Your opponents can''t choose your moods with a value of 2 or less.', 'XEMB'),
    (179, 'Elusiveness', 'elusiveness', 'blue', 'rare', 1, NULL, 0, 1, 0, 'While in play — If an opponent''s effect would put one of your moods into the discard pile, put it into your hand instead.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (167, (SELECT id FROM sets WHERE code = 'XEMB'), 30),
    (164, (SELECT id FROM sets WHERE code = 'XEMB'), 27),
    (179, (SELECT id FROM sets WHERE code = 'XEMB'), 42);

UPDATE schema_version SET version = '1.64.17' WHERE id = 1;

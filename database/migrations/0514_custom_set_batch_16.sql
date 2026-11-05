-- Community set 'Emotional Baggage' (XEMB), batch 16: the next-turn and name-ban cards (Deflection, Haunting).

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (176, 'Deflection', 'deflection', 'blue', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood — Put one of your opponents'' moods into its player''s hand. That player can''t play cards with that name during their next turn.', 'XEMB'),
    (202, 'Haunting', 'haunting', 'black', 'mythic', 2, NULL, 0, 0, 1, 'After playing this mood — You may discard any number of cards from your hand. If you do, you may play that many additional moods from the discard pile on your next turn.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (176, (SELECT id FROM sets WHERE code = 'XEMB'), 39),
    (202, (SELECT id FROM sets WHERE code = 'XEMB'), 68);

UPDATE schema_version SET version = '1.64.18' WHERE id = 1;

-- Community set 'Emotional Baggage' (XEMB), batch 4: the round-win count cards (BoardState::roundWinsOf(), AfterScoringTriggerEffect).

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (251, 'Resolve', 'resolve', 'green', 'common', 3, 6, 0, 1, 0, 'While in play — This mood''s value is 6 if you have won fewer rounds than each other player.', 'XEMB'),
    (255, 'Vindication', 'vindication', 'green', 'rare', 2, NULL, 0, 1, 0, 'While in play — This mood''s value increases by 2 for each round you have won.', 'XEMB'),
    (247, 'Perseverance', 'perseverance', 'green', 'uncommon', 2, NULL, 0, 1, 0, 'While in play — After scoring, if you have won fewer rounds than each other player, this mood''s value permanently increases by 2.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (251, (SELECT id FROM sets WHERE code = 'XEMB'), 124),
    (255, (SELECT id FROM sets WHERE code = 'XEMB'), 128),
    (247, (SELECT id FROM sets WHERE code = 'XEMB'), 120);

UPDATE schema_version SET version = '1.64.4' WHERE id = 1;

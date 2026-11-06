-- Community set 'Emotional Baggage' (XEMB), batch 17: the round-order and round-rule cards (Compassion, Forgetfulness, Levelheadedness).
-- game_rounds.round_flat_mood_score: what every mood scores this round (Levelheadedness); round_no_end_draw: no end-of-round draw this round (Forgetfulness).

ALTER TABLE game_rounds ADD COLUMN round_flat_mood_score TINYINT NULL DEFAULT NULL, ADD COLUMN round_no_end_draw TINYINT(1) NOT NULL DEFAULT 0;

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (149, 'Compassion', 'compassion', 'white', 'rare', 2, NULL, 0, 1, 0, 'While in play — The player with the fewest moods goes first each round, regardless of who won the previous round. (If players are tied, the usual order applies.)', 'XEMB'),
    (155, 'Forgetfulness', 'forgetfulness', 'white', 'rare', 2, NULL, 0, 0, 1, 'After playing this mood — The winner of the previous round loses a round win. This round, no one draws a card at the end of the round.', 'XEMB'),
    (160, 'Levelheadedness', 'levelheadedness', 'white', 'rare', 1, NULL, 0, 0, 1, 'After playing this mood — This round, each mood scores 2 instead of its value.', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (149, (SELECT id FROM sets WHERE code = 'XEMB'), 11),
    (155, (SELECT id FROM sets WHERE code = 'XEMB'), 17),
    (160, (SELECT id FROM sets WHERE code = 'XEMB'), 22);

UPDATE schema_version SET version = '1.64.19' WHERE id = 1;

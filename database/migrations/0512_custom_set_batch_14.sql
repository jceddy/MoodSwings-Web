-- Community set 'Emotional Baggage' (XEMB), batch 14: the play-restriction cards (Obstinacy, Restraint, Overconfidence, Pettiness, Rudeness, Defensiveness).
-- game_players.pending_turn_restrictions: what waits for a player's next turn (Pettiness, Overconfidence, Rudeness); game_rounds.turn_flags: the current turn's plays made and restrictions in force.

ALTER TABLE game_players ADD COLUMN pending_turn_restrictions LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL CHECK (json_valid(pending_turn_restrictions));
ALTER TABLE game_rounds ADD COLUMN turn_flags LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL CHECK (json_valid(turn_flags));

INSERT INTO cards (id, name, effect_key, color, rarity, base_value, alt_value, has_to_play_ability, has_while_in_play_ability, has_after_playing_ability, rules_text, art_set_code) VALUES
    (245, 'Obstinacy', 'obstinacy', 'green', 'common', 5, NULL, 0, 1, 0, 'While in play — You can only play one mood per turn.', 'XEMB'),
    (165, 'Restraint', 'restraint', 'white', 'mythic', 4, NULL, 0, 1, 0, 'While in play — Moods can''t let any player play additional moods. (This doesn''t affect Hurt Feelings.)', 'XEMB'),
    (183, 'Overconfidence', 'overconfidence', 'blue', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood — You may play up to two additional moods this turn. If you do, you can''t play moods during your next turn.', 'XEMB'),
    (211, 'Pettiness', 'pettiness', 'black', 'uncommon', 2, NULL, 0, 0, 1, 'After playing this mood — You may discard a card from your hand. If you do, choose a player. That player can''t play moods during their next turn.', 'XEMB'),
    (166, 'Rudeness', 'rudeness', 'white', 'rare', 1, NULL, 0, 0, 1, 'After playing this mood — Each other player can''t play additional moods during their next turn, and the after-playing effects of moods they play during that turn don''t happen. (This doesn''t affect Hurt Feelings.)', 'XEMB'),
    (175, 'Defensiveness', 'defensiveness', 'blue', 'uncommon', 3, NULL, 0, 1, 0, 'While in play — The after-playing effects of additional moods your opponents play don''t happen. (This doesn''t affect Hurt Feelings.)', 'XEMB');

INSERT INTO card_sets (card_id, set_id, collector_number) VALUES
    (245, (SELECT id FROM sets WHERE code = 'XEMB'), 118),
    (165, (SELECT id FROM sets WHERE code = 'XEMB'), 28),
    (183, (SELECT id FROM sets WHERE code = 'XEMB'), 47),
    (211, (SELECT id FROM sets WHERE code = 'XEMB'), 77),
    (166, (SELECT id FROM sets WHERE code = 'XEMB'), 29),
    (175, (SELECT id FROM sets WHERE code = 'XEMB'), 38);

UPDATE schema_version SET version = '1.64.16' WHERE id = 1;

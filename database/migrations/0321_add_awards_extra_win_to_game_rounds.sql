-- Reported live: "corruption double wins should apply even if corruption
-- is no longer in play at the end of the round."
--
-- Root cause: Corruption's "the winner of the current round wins two
-- rounds instead of one" choice was tagged as per-card effectState
-- ('awardsExtraWin') on Corruption's own card. That choice is locked in
-- the instant Corruption's afterPlaying() resolves -- Corruption's own
-- printed text has no "while in play" condition on it -- so tagging it
-- on the card meant the marker silently vanished if Corruption left play
-- (discarded, bounced, etc.) before the round it was played in actually
-- finished scoring. The exact same failure mode migrations 0141/0142
-- already fixed for Awe's own "no scoring this round" marker.
--
-- Fixed the same way: these two columns track the marker at the ROUND
-- level instead, read regardless of whether Corruption itself is still
-- in play, plus the source card/owner purely for
-- GameService::scoringEffectEntries()'s own "How scoring will be
-- affected" display entry (the same attribution 0142 added for Awe).
ALTER TABLE game_rounds
    ADD COLUMN awards_extra_win TINYINT(1) NOT NULL DEFAULT 0 AFTER skip_scoring_owner_game_player_id,
    ADD COLUMN awards_extra_win_source_card_id INT UNSIGNED DEFAULT NULL AFTER awards_extra_win,
    ADD COLUMN awards_extra_win_owner_game_player_id INT UNSIGNED DEFAULT NULL AFTER awards_extra_win_source_card_id;

ALTER TABLE game_rounds
    ADD CONSTRAINT fk_game_rounds_awards_extra_win_source_card FOREIGN KEY (awards_extra_win_source_card_id) REFERENCES game_cards (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_game_rounds_awards_extra_win_owner FOREIGN KEY (awards_extra_win_owner_game_player_id) REFERENCES game_players (id) ON DELETE SET NULL;

UPDATE schema_version SET version = '1.40.14' WHERE id = 1;

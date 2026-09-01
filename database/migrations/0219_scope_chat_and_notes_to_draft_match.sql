-- Issue #463: chat (issue #109) and the private notepad (issue #258)
-- currently reset to empty for every game in a draft match's own
-- best-of-three (games.draft_match_id/match_game_number, see
-- GameService::advanceDraftMatch()), and are entirely unusable during
-- the drafting/deck-building phase itself, since both were only ever
-- gated on a single game_id's own status being 'in_progress'.
--
-- Fix: key both off games.draft_match_id -- already the natural
-- match-level grouping every draft-family deck_type gets (any format,
-- any player count; NULL for duel/custom_duel/standard/team/
-- closed_team without a draft deck_type, which have no match concept
-- yet -- see the still-open issue #90) -- instead of game_id/
-- game_player_id, whenever a game belongs to one. A message/note row
-- still physically belongs to the specific game_id/game_player_id it
-- was written from (unchanged FK/cascade there, so it's still cleaned
-- up whenever THAT specific stale game is deleted -- see
-- GameService::deleteStaleCompletedGames()), but the READ side now
-- looks it up by draft_match_id when one is set, spanning every game
-- in the match instead of just one.
--
-- sender_game_player_id/game_player_id alone can't resolve a display
-- name or "is this seat mine" once the game they belong to has been
-- superseded by a later game in the same match (a new game_player_id
-- gets minted per game -- see advanceDraftMatch()), so both tables also
-- gain a match-stable sender_user_id/user_id column, resolved once at
-- write time from game_players.user_id and never re-derived via a join
-- that could later go stale.
ALTER TABLE game_chat_messages
    ADD COLUMN draft_match_id BIGINT UNSIGNED DEFAULT NULL AFTER game_id,
    ADD COLUMN sender_user_id INT UNSIGNED DEFAULT NULL AFTER sender_game_player_id;

UPDATE game_chat_messages gcm
    JOIN game_players gp ON gp.id = gcm.sender_game_player_id
    JOIN games g ON g.id = gcm.game_id
    SET gcm.sender_user_id = gp.user_id, gcm.draft_match_id = g.draft_match_id;

ALTER TABLE game_chat_messages
    MODIFY COLUMN sender_user_id INT UNSIGNED NOT NULL,
    ADD KEY idx_game_chat_messages_match (draft_match_id, id),
    -- Belt-and-suspenders only -- a message row's own game_id FK above
    -- already cascades it away once ITS specific game is deleted, which
    -- always happens no later than draft_matches' own orphan cleanup
    -- (GameService::deleteStaleCompletedGames()'s "delete every
    -- draft_matches row with no games left") removes the match itself,
    -- so this should never actually fire in practice.
    ADD CONSTRAINT fk_game_chat_messages_draft_match_id FOREIGN KEY (draft_match_id) REFERENCES draft_matches (id) ON DELETE CASCADE;

ALTER TABLE game_notes
    ADD COLUMN draft_match_id BIGINT UNSIGNED DEFAULT NULL AFTER game_player_id,
    ADD COLUMN user_id INT UNSIGNED DEFAULT NULL AFTER draft_match_id;

UPDATE game_notes gn
    JOIN game_players gp ON gp.id = gn.game_player_id
    JOIN games g ON g.id = gp.game_id
    SET gn.user_id = gp.user_id, gn.draft_match_id = g.draft_match_id;

-- (draft_match_id, user_id) uniquely identifies "this user's one note
-- for this whole match" -- MySQL's unique index treats every
-- draft_match_id IS NULL row as distinct from every other, so this adds
-- no constraint at all for non-match games, which stay uniquely keyed
-- by the existing uq_game_notes_game_player_id below exactly as before.
ALTER TABLE game_notes
    MODIFY COLUMN user_id INT UNSIGNED NOT NULL,
    ADD UNIQUE KEY uq_game_notes_match_user (draft_match_id, user_id),
    ADD CONSTRAINT fk_game_notes_draft_match_id FOREIGN KEY (draft_match_id) REFERENCES draft_matches (id) ON DELETE CASCADE;

UPDATE schema_version SET version = '1.29.7' WHERE id = 1;

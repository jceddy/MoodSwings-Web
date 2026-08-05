-- In-game chat (issue #109): lets seated players send each other text
-- messages while playing, rather than needing an out-of-band channel.
-- Append-only and game-scoped, the same shape as game_events (BIGINT id
-- as the ordering key, FK straight to games.id rather than a
-- (user_id, game_id) compound) -- unlike game_notes (one row per seat,
-- upsert-only), chat is genuinely many-rows-per-seat, so game_events is
-- the closer precedent. sender_game_player_id is NOT NULL (unlike
-- game_events.acting_game_player_id) since every chat message always has
-- a real sender -- chat is seated-players-only, spectators can neither
-- read nor write it (see GameService::chatMessagesFor()'s own docblock).
--
-- 'channel' + 'team_id' together support Open Team Play's own private
-- teammate-only channel alongside the whole-table one: 'table' messages
-- are always visible to everyone seated; 'team' messages are only ever
-- inserted for format 'team' (see GameService::postChatMessage() --
-- deliberately NOT Closed Team Play too, whose entire premise is that
-- information stays closed between teammates), and only visible to
-- seats sharing that same team_id. team_id is redundant with a join back to
-- game_players through sender_game_player_id, but storing it directly
-- keeps the read-side filter (WHERE channel = 'table' OR (channel =
-- 'team' AND team_id = :viewer_team_id)) a single indexed lookup rather
-- than a join on every poll -- this table is read on every 4s
-- GET /games/state poll (piggybacked rather than its own polling
-- endpoint), so keeping that query cheap matters more than it would for
-- an occasionally-opened dialog like game_notes/game_events.
CREATE TABLE IF NOT EXISTS game_chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT UNSIGNED NOT NULL,
    sender_game_player_id INT UNSIGNED NOT NULL,
    channel ENUM('table', 'team') NOT NULL DEFAULT 'table',
    team_id TINYINT UNSIGNED DEFAULT NULL,
    message_text VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_game_chat_messages_game (game_id, id),
    CONSTRAINT fk_game_chat_messages_game_id FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_chat_messages_sender FOREIGN KEY (sender_game_player_id) REFERENCES game_players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New chat-message notification preference (issue #108's own system),
-- default on like notify_your_turn/notify_friend_request/
-- notify_game_finished -- see NotificationService::notifyNewChatMessage().
ALTER TABLE notification_preferences
    ADD COLUMN notify_chat_message TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_game_finished;

UPDATE schema_version SET version = '1.11.9' WHERE id = 1;

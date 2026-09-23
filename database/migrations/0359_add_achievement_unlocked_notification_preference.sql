-- Achievements Phase 2: unlock notifications need their own opt-out
-- preference, same as every other notify_* event
-- (notify_your_turn/notify_friend_request/notify_game_finished/
-- notify_chat_message/notify_timeout_warning) already has. Defaults on
-- like the rest -- see NotificationService::notifyAchievementUnlocked().
ALTER TABLE notification_preferences
    ADD COLUMN notify_achievement_unlocked TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_timeout_warning;

UPDATE schema_version SET version = '1.51.2' WHERE id = 1;

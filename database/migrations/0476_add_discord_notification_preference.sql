-- Lets a user turn Discord DMs off while keeping web/Android push
-- notifications on -- see NotificationService::deliver() and
-- Discord\DiscordNotificationChannel::preferenceKey(). Defaults on like
-- every other notify_* preference.
ALTER TABLE notification_preferences
    ADD COLUMN notify_discord TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_achievement_unlocked;

UPDATE schema_version SET version = '1.62.25' WHERE id = 1;

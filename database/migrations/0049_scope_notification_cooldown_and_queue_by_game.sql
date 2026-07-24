-- Narrows migration 0048's global 5-minute per-user notification cooldown
-- (and its "queue and replace" companion) down to per-(user, scope)
-- instead: a player in several games at once could get more than one push
-- within 5 minutes overall, but never more than one within 5 minutes
-- *about the same game* -- and a notification queued for one game must
-- never bump (or be bumped by) one already queued for a different game.
-- "scope" is either "game:{id}" (any it's-your-turn/game-finished
-- notification about that game, regardless of which of the several
-- tag suffixes triggered it -- see PushNotificationService::
-- notifyYourTurn()) or the constant "friend_request" (not tied to any
-- game at all) -- see NotificationScope.

-- Replaces notification_preferences.last_notified_at's single
-- global-per-user timestamp with one row per (user, scope) -- PRIMARY KEY
-- can't allow NULL in either column, which is exactly why "friend_request"
-- is a literal string constant here rather than a NULL/absent game_id.
CREATE TABLE IF NOT EXISTS notification_cooldowns (
    user_id INT UNSIGNED NOT NULL,
    scope VARCHAR(32) NOT NULL,
    last_notified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, scope),
    CONSTRAINT fk_notification_cooldowns_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The column it replaces -- see NotificationCooldownRepository for the
-- new per-scope wasNotifiedRecently()/markNotified() this table backs.
ALTER TABLE notification_preferences DROP COLUMN last_notified_at;

-- queued_notifications keyed by (user_id, scope) instead of user_id alone
-- -- same upsert-replace reasoning as before (INSERT ... ON DUPLICATE KEY
-- UPDATE in QueuedNotificationRepository::enqueue()), just scoped so a
-- reminder queued for game 4 can no longer be silently replaced by one
-- for game 42, or vice versa.
ALTER TABLE queued_notifications
    ADD COLUMN scope VARCHAR(32) NOT NULL DEFAULT '' AFTER user_id,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (user_id, scope);

UPDATE schema_version SET version = '1.1.1' WHERE id = 1;

-- Browser push notifications (issue #108), first pass: Push API +
-- Notifications API via a Service Worker. Discord notifications are
-- explicitly deferred to a later change (more planning/design work needed
-- there per the issue) -- everything here is web-push only.
--
-- push_subscriptions holds one row per subscribed browser/device (a user
-- can have several -- phone, laptop, etc.), storing exactly what
-- PushManager.subscribe() returns: the endpoint URL and the two keys
-- (p256dh, auth) needed to encrypt a payload to it. endpoint is unique on
-- its own (it already uniquely identifies a subscription, no matter which
-- user row it's attached to) so re-subscribing the same browser updates
-- its keys in place rather than accumulating duplicates.
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    endpoint VARCHAR(1024) NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    p256dh_key VARCHAR(255) NOT NULL,
    auth_key VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_subscriptions_endpoint_hash (endpoint_hash),
    KEY idx_push_subscriptions_user_id (user_id),
    CONSTRAINT fk_push_subscriptions_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- endpoint itself can run past most reasonable index key-length limits
-- (push services occasionally issue very long URLs), so uniqueness is
-- enforced on a SHA-256 hash of it instead of the raw column -- same
-- reasoning as sessions.token_hash/email_verifications.token_hash above,
-- just for length rather than secrecy.

-- One row per user, created lazily the first time they either change a
-- preference (NotificationPreferenceRepository::save()) or actually get
-- sent a notification (PushNotificationService::notify(), to record
-- last_notified_at below) -- forUser() still returns the all-true
-- defaults without a row existing at all. Three independent toggles for
-- this pass's three event types; a per-channel column (rather than a
-- generic event_type/channel pair table) is deliberately simple since
-- there's only one channel (browser push) today -- Discord notifications,
-- when they land, get their own columns here rather than a redesign.
--
-- last_notified_at backs a global 5-minute per-user cooldown
-- (PushNotificationService::notify()) -- regardless of event type or
-- which game it's about, a user who was already sent a push within the
-- last 5 minutes doesn't get another one, so someone actively playing
-- through several turns/decisions in a row isn't spammed with one
-- notification per event.
CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id INT UNSIGNED NOT NULL,
    notify_your_turn TINYINT(1) NOT NULL DEFAULT 1,
    notify_friend_request TINYINT(1) NOT NULL DEFAULT 1,
    notify_game_finished TINYINT(1) NOT NULL DEFAULT 1,
    last_notified_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_notification_preferences_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backs the cooldown's "queue and replace" behavior
-- (PushNotificationService::notify()): rather than simply dropping a
-- notification that arrives during another one's 5-minute cooldown, it's
-- queued here instead -- at most one row per user, so a second (or
-- third, or tenth) notification arriving before the queued one is ever
-- delivered just overwrites it in place (ON DUPLICATE KEY UPDATE), same
-- reasoning as push_subscriptions' own upsert-by-endpoint. The result:
-- once bin/send_queued_notifications.php's cron flush (~every 15
-- minutes) gets around to it, a user who was busy gets exactly one
-- notification reflecting whatever was truly last, never a backlog of
-- everything that happened in between.
--
-- preference_key is stored alongside the rendered title/body/url/tag so
-- flushQueuedNotifications() can re-check that preference at flush time
-- (the user may have turned it off between queueing and delivery) without
-- needing to know which specific notifyXxx() call produced this row.
-- GameService::clearQueuedNotificationForGamePlayer()/
-- PushNotificationService::clearQueuedForGame()/clearQueuedFriendRequest()
-- delete a queued row early if the player takes the action it would have
-- reminded them about before the cron flush ever runs.
CREATE TABLE IF NOT EXISTS queued_notifications (
    user_id INT UNSIGNED NOT NULL,
    preference_key VARCHAR(32) NOT NULL,
    title VARCHAR(255) NOT NULL,
    body VARCHAR(500) NOT NULL,
    url VARCHAR(255) NOT NULL,
    tag VARCHAR(64) NOT NULL,
    queued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_queued_notifications_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.1.0' WHERE id = 1;

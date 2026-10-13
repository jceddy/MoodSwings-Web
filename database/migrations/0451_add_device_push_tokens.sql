-- Native push notifications (Unity client, Android first): one row per installed app instance, holding the
-- FCM registration token the app was handed. NotificationService fans a notification out to
-- Notifications\FcmNotificationChannel, which sends to every token a user has -- the same shape as
-- push_subscriptions for browsers, just with one opaque token instead of an endpoint plus keys.
--
-- token_hash (SHA-256 of the token) carries the uniqueness constraint, like push_subscriptions.endpoint_hash:
-- tokens run long enough that a unique index on the raw column is awkward, and re-registering the same
-- install (after sign-in as someone else, say) must move the row to the new user rather than duplicate it.
CREATE TABLE IF NOT EXISTS device_push_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    platform ENUM('android', 'ios') NOT NULL,
    token VARCHAR(512) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_device_push_tokens_token_hash (token_hash),
    KEY idx_device_push_tokens_user_id (user_id),
    CONSTRAINT fk_device_push_tokens_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.62.0' WHERE id = 1;

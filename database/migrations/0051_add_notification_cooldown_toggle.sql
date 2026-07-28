-- A user-facing toggle to disable NotificationService's own 5-minute
-- per-scope cooldown (NotificationService::COOLDOWN_SECONDS) -- with it
-- on, every notification for that user is delivered immediately instead
-- of being subject to the cooldown/queue-and-replace fallback (see
-- migration 0048's own notification_preferences docblock), so someone
-- who wants every single "it's your turn"/"friend request"/"game
-- finished" event pushed as it happens, even several in quick
-- succession, can opt into that. Defaults to 0 (cooldown enabled) so
-- every existing user's behavior is unchanged until they explicitly turn
-- this on -- mirrored by NotificationPreferenceRepository::forUser()'s
-- own defaults for a user who has no row here yet (every notify_* toggle
-- true, disable_cooldown false).
ALTER TABLE notification_preferences
    ADD COLUMN disable_cooldown TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_game_finished;

UPDATE schema_version SET version = '1.3.0' WHERE id = 1;

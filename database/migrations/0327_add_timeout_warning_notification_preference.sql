-- Reported live: "Can we also add some kind of indicator for action
-- timeout if it's close (like within 15 minutes)? - also send a
-- notification if either the action timeout or full game chess clock
-- timeout becomes less than 15 minutes left" -- the notification half of
-- that request needs its own opt-out preference, same as every other
-- notify_* event (notify_your_turn/notify_friend_request/
-- notify_game_finished/notify_chat_message) already has. Defaults on
-- like the rest -- see NotificationService::notifyTimeoutWarning()'s own
-- docblock. The visual-indicator half (game.action_timeout_warning) is
-- computed on the fly from existing columns and needs no schema change.
ALTER TABLE notification_preferences
    ADD COLUMN notify_timeout_warning TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_chat_message;

UPDATE schema_version SET version = '1.42.2' WHERE id = 1;

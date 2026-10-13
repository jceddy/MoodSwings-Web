-- Native push (FCM) fix: the cron/background scripts (advance_automated_turns, apply_game_timeouts,
-- recheck_automated_turn, run_bot_search, send_queued_notifications) each build their own NotificationService and
-- were still created with only the browser-push and Discord channels, so notifications they raise -- a bot
-- finishing its turn, a timeout warning, a cooldown-delayed notification being flushed -- never reached the
-- Unity app's devices. They now include FcmNotificationChannel like public/index.php does. No schema change.

UPDATE schema_version SET version = '1.62.1' WHERE id = 1;

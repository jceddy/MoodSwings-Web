-- Bot AI only (php-app/src/Bot/BotPlayerService.php): Disillusionment's
-- color pick is scored with the real round score, always takes a color
-- that only hits other players' moods, and never hits the bot's own
-- protected moods. No schema change.
UPDATE schema_version SET version = '1.62.27' WHERE id = 1;

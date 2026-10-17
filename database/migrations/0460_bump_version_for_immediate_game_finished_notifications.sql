-- Notifications fix: game-finished (win/loss) notifications are now always sent immediately instead of being
-- queued behind the game's 5-minute cooldown (a "your turn" notification sent just before the game ended used to
-- delay the result by up to the 15-minute queue flush). No schema change.

UPDATE schema_version SET version = '1.62.9' WHERE id = 1;

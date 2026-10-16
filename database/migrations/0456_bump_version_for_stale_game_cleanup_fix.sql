-- Stale-game cleanup fix: the daily cron (bin/expire_and_delete_stale_games.php) died on the first old
-- completed game that still had rows pointing at its players (game_team_decisions, pending decisions,
-- chaos draft offers -- foreign keys without ON DELETE CASCADE), so nothing was ever deleted. Those rows
-- are now cleared before each game is deleted, and a game that still can't be deleted is logged and
-- skipped instead of ending the run. No schema change.

UPDATE schema_version SET version = '1.62.5' WHERE id = 1;

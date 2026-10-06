-- Format Purist's bar never filled (reported live): AchievementService::
-- checkFormatPurist() kept per-format play counts in user_format_play_counts
-- but never reported them to user_achievements.progress, which the bar reads.
-- The code now reports the best single format's count after every game; this
-- backfills everyone who already has games on record. GREATEST() only ever
-- raises progress, and an already-unlocked row is left alone, so it is safe
-- to run more than once.
INSERT INTO user_achievements (user_id, achievement_id, progress)
SELECT c.user_id, a.id, MAX(c.games_played)
FROM user_format_play_counts c
JOIN achievements a ON a.slug = 'format-purist'
GROUP BY c.user_id, a.id
ON DUPLICATE KEY UPDATE progress = IF(unlocked_at IS NULL, GREATEST(progress, VALUES(progress)), progress);

UPDATE schema_version SET version = '1.61.8' WHERE id = 1;

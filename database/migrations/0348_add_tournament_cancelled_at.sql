-- Reported live: tournaments need to be cleaned up a week after being
-- cancelled/completed, folded into the existing game/match cleanup cron
-- (bin/expire_and_delete_stale_games.php). completed_at already exists
-- for that half of the rule; cancelled_at is new, since
-- TournamentRepository::markCancelled() never recorded when a
-- tournament was actually cancelled before now -- TournamentService::
-- deleteStaleTournaments() needs it to know how long a cancelled
-- tournament has been sitting around, the same way completed_at already
-- lets it judge a completed one.
--
-- Backfilled to NOW() for every already-cancelled tournament (there's
-- no real cancellation time to recover) so none of them sit around
-- forever uncleaned just because they predate this column -- they
-- become eligible for cleanup 7 days after this migration runs, same as
-- a tournament cancelled today.
ALTER TABLE tournaments
    ADD COLUMN cancelled_at TIMESTAMP NULL DEFAULT NULL AFTER completed_at;

UPDATE tournaments SET cancelled_at = NOW() WHERE status = 'cancelled' AND cancelled_at IS NULL;

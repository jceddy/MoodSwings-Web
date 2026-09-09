-- Reported live: "based on the results I'm seeing when I test this
-- process must be crashing basically all the time." advanceTacticalBotSearch()'s
-- own stale-job fallback (migration 0236) already had a plausible
-- explanation waiting: this app deploys to Bluehost shared cPanel
-- hosting (see deploy.yml/deploy-dev.yml), and
-- launchTacticalBotSearchJob()'s background search process is started
-- via a bare `exec("php ... > /dev/null 2>&1 &")` with no
-- `setsid`/`nohup`/process-group detachment -- exactly the kind of
-- fire-and-forget spawn that shared-hosting process supervision
-- (CageFS/LVE limits, suexec/FastCGI process trees) is well known to
-- kill outright the moment the launching HTTP request's own process
-- group tears down, rather than the search genuinely running long and
-- crashing partway through. Until now there was no way to tell those
-- two situations apart from the bot_search_jobs row alone: a job that
-- died on arrival looks identical to one still genuinely thinking,
-- right up until the same 30-second stale-grace check treats both
-- alike.
--
-- heartbeat_at is stamped by the background process itself, immediately
-- on boot and then periodically while its search loop runs (see
-- SearchBotPlayerService's own periodic-checkpoint callback) -- so a
-- stale job with heartbeat_at still NULL means the process never even
-- got PHP running at all (the hosting/exec() theory above), one with an
-- old heartbeat_at died partway through a real search, and one with a
-- recent heartbeat_at was simply still alive and slow, not crashed.
--
-- best_action_card_id/best_action_choices/best_action_recorded_at are
-- the same periodic checkpoint's OTHER half: the best root action the
-- search has found so far, so advanceTacticalBotSearch()'s stale-job
-- fallback can apply that (with a "recovered from a stalled search"
-- reasoning note) instead of unconditionally discarding every rollout
-- this job ever ran and replaying via the plain heuristic bot with no
-- reasoning at all, the second half of what was reported live: "is
-- there any way that we could have the tactical bot use any results
-- found so far from a partial search when it gets to time instead of
-- completely abandoning any information." best_action_recorded_at
-- (rather than treating a NULL best_action_card_id as "no snapshot yet")
-- is what actually distinguishes "no checkpoint has landed yet" from "a
-- checkpoint landed and its own best action was a legitimate pass."
ALTER TABLE bot_search_jobs
    ADD COLUMN heartbeat_at TIMESTAMP NULL DEFAULT NULL AFTER started_at,
    ADD COLUMN best_action_card_id INT UNSIGNED NULL DEFAULT NULL AFTER error_message,
    ADD COLUMN best_action_choices JSON DEFAULT NULL AFTER best_action_card_id,
    ADD COLUMN best_action_recorded_at TIMESTAMP NULL DEFAULT NULL AFTER best_action_choices;

UPDATE schema_version SET version = '1.39.10' WHERE id = 1;

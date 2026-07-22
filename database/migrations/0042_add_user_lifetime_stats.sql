-- Lifetime user stats (issue #106): a persisted, per-user running total of
-- game and (best-of-three draft) match wins/losses, rather than deriving it
-- live from `games`/`draft_matches` history every time it's viewed. Deriving
-- it live would work today, but the whole point of "lifetime" is that it
-- must survive old game data being cleaned up later -- so this table is
-- incrementally updated the moment each game/match actually completes (see
-- GameService::recordGameCompletionStats()/recordMatchCompletionStats()),
-- and only ever backfilled from history once, right here, for whatever's
-- already been played before this migration runs.
--
-- Tournament standings (also mentioned in #106) are deliberately not
-- covered -- that depends on the tournament system (#91) existing first,
-- same as the issue itself notes. Scoped narrow on purpose: just game_wins/
-- game_losses (every format) and match_wins/match_losses (quick_draft/
-- winston_draft/grid_draft best-of-three matches only, since that's the
-- only "match" grouping that exists yet -- non-draft best-of-three (#90)
-- would need its own increment call sites once it exists, same shape as
-- this).
--
-- One row per user who has ever finished a game, created lazily (see
-- GameService::bumpLifetimeStats()) rather than one row per registered
-- user up front -- a brand new user with no games played yet just has no
-- row, read back as all-zero by lifetimeStatsFor() rather than needing an
-- INSERT at registration time for a stat nothing has happened to yet.
CREATE TABLE IF NOT EXISTS user_lifetime_stats (
    user_id INT UNSIGNED NOT NULL,
    game_wins INT UNSIGNED NOT NULL DEFAULT 0,
    game_losses INT UNSIGNED NOT NULL DEFAULT 0,
    match_wins INT UNSIGNED NOT NULL DEFAULT 0,
    match_losses INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_lifetime_stats_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time backfill from existing history (see the table comment above --
-- every future win/loss is instead recorded incrementally as it happens,
-- never re-derived like this again). A completed game's winner is either a
-- single game_players row (winner_game_player_id, winner_team_id NULL) or
-- an entire team (winner_team_id set, matching every game_players row with
-- that team_id) -- see "Open Team Play" in php-app/README.md for why both
-- exist side by side. A completed draft match's winner is simply
-- draft_matches.winner_user_id; draft_match_players names everyone who
-- played it. Only 'completed' rows count -- an 'abandoned' game (e.g.
-- Winston Draft's own auto-loss for under-12-cards, which completes the
-- MATCH without ever completing game 1 -- see finalizeWinstonDraft())
-- correctly contributes to match_wins/match_losses here but not
-- game_wins/game_losses, matching what recordGameCompletionStats() would
-- have done had it existed at the time.
INSERT INTO user_lifetime_stats (user_id, game_wins, game_losses, match_wins, match_losses)
SELECT
    u.id,
    COALESCE(gw.n, 0),
    COALESCE(gl.n, 0),
    COALESCE(mw.n, 0),
    COALESCE(ml.n, 0)
FROM users u
LEFT JOIN (
    SELECT gp.user_id, COUNT(*) AS n
    FROM games g
    JOIN game_players gp ON gp.game_id = g.id
    WHERE g.status = 'completed'
      AND (
          (g.winner_team_id IS NULL AND gp.id = g.winner_game_player_id)
          OR (g.winner_team_id IS NOT NULL AND gp.team_id = g.winner_team_id)
      )
    GROUP BY gp.user_id
) gw ON gw.user_id = u.id
LEFT JOIN (
    SELECT gp.user_id, COUNT(*) AS n
    FROM games g
    JOIN game_players gp ON gp.game_id = g.id
    WHERE g.status = 'completed'
      AND NOT (
          (g.winner_team_id IS NULL AND gp.id = g.winner_game_player_id)
          OR (g.winner_team_id IS NOT NULL AND gp.team_id = g.winner_team_id)
      )
    GROUP BY gp.user_id
) gl ON gl.user_id = u.id
LEFT JOIN (
    SELECT dmp.user_id, COUNT(*) AS n
    FROM draft_matches dm
    JOIN draft_match_players dmp ON dmp.draft_match_id = dm.id
    WHERE dm.status = 'completed' AND dmp.user_id = dm.winner_user_id
    GROUP BY dmp.user_id
) mw ON mw.user_id = u.id
LEFT JOIN (
    SELECT dmp.user_id, COUNT(*) AS n
    FROM draft_matches dm
    JOIN draft_match_players dmp ON dmp.draft_match_id = dm.id
    WHERE dm.status = 'completed' AND dmp.user_id != dm.winner_user_id
    GROUP BY dmp.user_id
) ml ON ml.user_id = u.id
WHERE gw.n IS NOT NULL OR gl.n IS NOT NULL OR mw.n IS NOT NULL OR ml.n IS NOT NULL
ON DUPLICATE KEY UPDATE
    game_wins = VALUES(game_wins),
    game_losses = VALUES(game_losses),
    match_wins = VALUES(match_wins),
    match_losses = VALUES(match_losses);

UPDATE schema_version SET version = '0.15.0' WHERE id = 1;

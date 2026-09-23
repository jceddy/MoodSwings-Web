-- Two more small per-user counters for achievements Phase 2's Marathon
-- Session ("complete 3 games in a single calendar day") and Rematch!
-- ("play 5 games against the same opponent") -- both need state that
-- can't be expressed by achievements.progress' single running counter
-- (a day boundary resets, and "the same opponent" is a per-pair max),
-- and both have to be recorded incrementally at game-completion time for
-- the same reason everything else here is: completed games are deleted
-- after 7 days, so nothing can be recomputed from game history later.
CREATE TABLE IF NOT EXISTS user_daily_game_counts (
    user_id INT UNSIGNED NOT NULL,
    play_date DATE NOT NULL,
    games_played INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, play_date),
    CONSTRAINT fk_user_daily_game_counts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_opponent_game_counts (
    user_id INT UNSIGNED NOT NULL,
    opponent_user_id INT UNSIGNED NOT NULL,
    games_played INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, opponent_user_id),
    CONSTRAINT fk_user_opponent_game_counts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_opponent_game_counts_opponent FOREIGN KEY (opponent_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '1.51.1' WHERE id = 1;

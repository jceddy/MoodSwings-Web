-- games already tracks Created Time (created_at) and Started Time
-- (started_at, set by GameService::startGame()) and Completed Time
-- (completed_at, set once the game's winner is decided) -- this adds the
-- fourth: Last Move Time, set by GameService::touchLastMoveAt() after
-- every successful playMood()/pass()/respondToDecision() call, so the
-- lobby can tell a stalled game apart from an actively-progressing one
-- and sort by recent activity rather than just when the game began.
ALTER TABLE games
    ADD COLUMN last_move_at TIMESTAMP NULL DEFAULT NULL AFTER started_at;

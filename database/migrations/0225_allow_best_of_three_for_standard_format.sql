-- Issue #90 follow-up: extends game_matches' own best-of-three match
-- wrapper (migration 0223, so far Duel/Open Team Play/Closed Team Play
-- only) to 'standard' (Traditional) too. Only meaningful when exactly 2
-- players are seated -- with 3-4, "first to 2 game wins" no longer names
-- a single opponent, the same reason draftGamesToWin() itself already
-- falls back to a single game once more than 2 players share a draft
-- match. GameService::createGame() enforces that player-count
-- restriction; this migration just widens the column enough to store the
-- format at all.
ALTER TABLE game_matches
    MODIFY COLUMN format ENUM('duel', 'team', 'closed_team', 'standard') NOT NULL;

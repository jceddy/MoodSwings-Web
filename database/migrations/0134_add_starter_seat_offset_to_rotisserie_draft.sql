-- Fixes a real bug, caught live: in Team Play/Closed Team Play Rotisserie
-- Draft games, the game's creator picked first in EVERY match, no
-- exceptions -- confirmed 15/15 trials for both formats.
--
-- GameService::rotisserieDraftPickUserId($userIds, 0) always resolves to
-- $userIds[0] for the very first pick. For plain 'draft' format, $userIds
-- is GameService::shuffledSeatOrder()'d (migration 0132), so seat 0 is
-- genuinely random. But 'team'/'closed_team' seating comes from
-- seatOrderForTeamGame()/seatOrderForClosedTeamGame() instead, which
-- deliberately keep seating FIXED -- creator always at seat 0 -- so
-- partners sit adjacent/across the table as those formats require
-- (migration 0132 explicitly left them alone for exactly this reason).
-- Quick/Winston/Grid Draft avoid this same trap by separately
-- randomizing who picks first via $userIds[array_rand($userIds)],
-- independent of seat order -- but Rotisserie Draft's own turn-order
-- function never got that same treatment; it just always started at
-- literal seat index 0.
--
-- This new column stores a random 0..(playerCount-1) rotation applied to
-- every rotisserieDraftPickUserId() seat-index lookup for this match
-- (see GameService::initializeRotisserieDraft()/
-- submitRotisserieDraftPick()), chosen once and fixed for the whole
-- draft -- it rotates *which* seat is treated as "seat 0" for pick-order
-- purposes without touching the actual seating/team assignment, exactly
-- mirroring what array_rand() already does for the other three draft
-- types, and without disturbing the snake-draft shape or neighbor
-- relationships (a cyclic rotation preserves both).
ALTER TABLE draft_rotisserie_state
    ADD COLUMN starter_seat_offset TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER cutoff_count;

UPDATE schema_version SET version = '1.25.9' WHERE id = 1;

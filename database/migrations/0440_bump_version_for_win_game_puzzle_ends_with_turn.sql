-- A "win the game this turn" puzzle (goal_type 'win_game') is now lost when
-- the solver's turn ends without winning, instead of refreshing into
-- another turn. Pure code change; no schema change.
UPDATE schema_version SET version = '1.61.3' WHERE id = 1;

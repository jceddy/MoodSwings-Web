-- Panic: bots bounce their own Compulsion/Suspicion/Intimidation/Paranoia
-- for a replay when it doesn't cost them the round, and never bounce an
-- opponent's unless it wins or saves the game. Pure code change; no schema
-- change.
UPDATE schema_version SET version = '1.59.4' WHERE id = 1;

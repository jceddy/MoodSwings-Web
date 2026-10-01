-- Thrill: bots bounce their own Compulsion/Suspicion/Intimidation/Paranoia
-- to replay them the same turn (capped at the opposing hand size). Pure code
-- change; no schema change.
UPDATE schema_version SET version = '1.59.5' WHERE id = 1;

-- Thrill: bots bounce their own Compulsion/Suspicion/Intimidation/Paranoia
-- (capped at the opposing hand size), Joy (always) and Charity (with another
-- card in hand) to replay them the same turn. Pure code change; no schema
-- change.
UPDATE schema_version SET version = '1.59.5' WHERE id = 1;

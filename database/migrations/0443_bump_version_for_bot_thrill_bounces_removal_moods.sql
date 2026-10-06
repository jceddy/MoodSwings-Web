-- Bots now bounce their own Anger, Hate and Shock with Thrill to replay them
-- whenever there are targets. Pure code change; no schema change.
UPDATE schema_version SET version = '1.61.6' WHERE id = 1;

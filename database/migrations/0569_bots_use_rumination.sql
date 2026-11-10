-- Bots now use Rumination to replay a mood (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.73' WHERE id = 1;

-- Bots now use Fear to replay a mood and as a sacrifice (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.50' WHERE id = 1;

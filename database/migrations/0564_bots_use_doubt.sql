-- Bots now use Doubt's reveal (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.68' WHERE id = 1;

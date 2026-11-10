-- Bots now use Bravado's optional discard (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.48' WHERE id = 1;

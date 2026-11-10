-- Bots now use Admiration (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.66' WHERE id = 1;

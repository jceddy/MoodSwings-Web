-- Bots now use Worry (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.59' WHERE id = 1;

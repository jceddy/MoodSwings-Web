-- Bots now use Infatuation's two-mood sacrifice (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.52' WHERE id = 1;

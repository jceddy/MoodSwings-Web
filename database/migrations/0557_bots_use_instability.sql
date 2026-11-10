-- Bots now use Instability's swap (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.61' WHERE id = 1;

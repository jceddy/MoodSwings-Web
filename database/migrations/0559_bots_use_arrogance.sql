-- Bots now use Arrogance's steal (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.63' WHERE id = 1;

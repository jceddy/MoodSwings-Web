-- Bots now use Wastefulness's mill (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.74' WHERE id = 1;

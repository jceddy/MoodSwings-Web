-- Bots now use Shrewdness's swap (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.62' WHERE id = 1;

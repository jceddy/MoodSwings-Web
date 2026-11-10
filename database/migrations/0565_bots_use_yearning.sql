-- Bots now use Yearning's trade (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.69' WHERE id = 1;

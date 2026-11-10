-- Bots now use Encouragement's dice boost (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.70' WHERE id = 1;

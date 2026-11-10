-- Bots now use Hostility (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.58' WHERE id = 1;

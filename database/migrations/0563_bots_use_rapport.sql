-- Bots now use Rapport's loots (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.67' WHERE id = 1;

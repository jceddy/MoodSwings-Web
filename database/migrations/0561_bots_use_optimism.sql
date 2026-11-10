-- Bots now use Optimism and answer the repeated effect's own choices (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.65' WHERE id = 1;

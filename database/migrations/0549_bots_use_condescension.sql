-- Bots now use Condescension's card gift (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.53' WHERE id = 1;

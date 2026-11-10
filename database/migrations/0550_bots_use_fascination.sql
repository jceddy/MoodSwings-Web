-- Bots now use Fascination's card gift (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.54' WHERE id = 1;

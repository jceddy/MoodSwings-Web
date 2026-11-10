-- Bots now use Corruption and see its double win in their round-win predictions (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.64' WHERE id = 1;

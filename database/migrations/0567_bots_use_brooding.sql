-- Bots now use Brooding's loot (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.71' WHERE id = 1;

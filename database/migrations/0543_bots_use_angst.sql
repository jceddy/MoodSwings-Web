-- Bots now use Angst's optional discard (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.47' WHERE id = 1;

-- Bots now use Pettiness to protect a narrow lead (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.60' WHERE id = 1;

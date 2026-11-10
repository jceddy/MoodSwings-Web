-- Bots now use Shame's discard-and-suppress (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.56' WHERE id = 1;

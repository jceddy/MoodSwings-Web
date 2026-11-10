-- Bots now use Faith's discard-and-suppress (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.55' WHERE id = 1;

-- Bots now use Longing's optional discard-and-pick-up instead of playing it as a bare mood (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.42' WHERE id = 1;

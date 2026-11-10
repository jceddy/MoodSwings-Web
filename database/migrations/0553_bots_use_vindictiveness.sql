-- Bots now use Vindictiveness (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.57' WHERE id = 1;

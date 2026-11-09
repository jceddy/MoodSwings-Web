-- Bots now opt in to Belligerence when discarding their opponents' low moods improves their standing (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.35' WHERE id = 1;

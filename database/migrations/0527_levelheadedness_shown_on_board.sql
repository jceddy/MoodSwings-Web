-- Levelheadedness's round-wide "each mood scores 2" is now listed among the board's scoring effects (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.31' WHERE id = 1;

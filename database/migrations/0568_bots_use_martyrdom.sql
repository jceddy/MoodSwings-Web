-- Bots now use Martyrdom's free sacrifice (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.72' WHERE id = 1;

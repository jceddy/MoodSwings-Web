-- Web UI only: playing Paranoia on yourself while an opponent has cards in
-- hand now asks for confirmation. No schema change.
UPDATE schema_version SET version = '1.62.28' WHERE id = 1;

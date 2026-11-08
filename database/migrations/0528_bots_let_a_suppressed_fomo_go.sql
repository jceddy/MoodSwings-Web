-- Bots no longer discard a card at the start of their turn to keep a suppressed FOMO (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.32' WHERE id = 1;

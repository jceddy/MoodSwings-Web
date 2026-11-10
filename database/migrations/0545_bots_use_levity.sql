-- Bots now use Levity's bottom-deck-and-draw (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.49' WHERE id = 1;

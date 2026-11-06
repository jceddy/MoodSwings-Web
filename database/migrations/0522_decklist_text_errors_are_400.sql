-- Saving a pasted decklist with an unrecognized card or unreadable line now answers 400 with the parser's message instead of a 500 (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.26' WHERE id = 1;

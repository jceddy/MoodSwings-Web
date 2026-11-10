-- Bots now use the pure-removal cards (Courage, Spite, Anxiety, Malevolence) (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.44' WHERE id = 1;

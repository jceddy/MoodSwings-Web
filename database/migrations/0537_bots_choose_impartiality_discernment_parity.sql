-- Bots now choose Impartiality's odd/even by the resulting round lead and Discernment's by the replaceable cards in hand (revealing them), instead of always odd (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.41' WHERE id = 1;

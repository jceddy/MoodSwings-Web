-- Bots now pick Orderliness's odd/even by the most common printed number among their moods, instead of always odd (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.33' WHERE id = 1;

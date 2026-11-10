-- Bots now use the conditional-removal cards (Disdain, Indignation, Distrust, Vigilance) (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.45' WHERE id = 1;

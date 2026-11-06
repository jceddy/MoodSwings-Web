-- Bots now name a target for Rashness whenever one exists, and discard the hand cards that would be good plays to Haunting (PHP only; no schema change).

UPDATE schema_version SET version = '1.64.28' WHERE id = 1;

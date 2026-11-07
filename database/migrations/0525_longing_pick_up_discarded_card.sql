-- Longing's pile picker now offers the card you are discarding, so it can be taken straight back (web UI + schema flag; no schema change).

UPDATE schema_version SET version = '1.64.29' WHERE id = 1;

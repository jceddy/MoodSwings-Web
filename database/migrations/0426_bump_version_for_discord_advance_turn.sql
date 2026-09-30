-- Discord board shows an Advance Turn button while the player's turn is
-- pending acknowledgment. Pure code change; no schema change.
UPDATE schema_version SET version = '1.59.2' WHERE id = 1;

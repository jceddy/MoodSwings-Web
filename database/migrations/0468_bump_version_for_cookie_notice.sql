-- Web client now shows a one-time, dismissible cookie notice. No schema
-- change -- version bump only.
UPDATE schema_version SET version = '1.62.17' WHERE id = 1;

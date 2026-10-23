-- The web client no longer overlays a value badge on a card rotated 180 degrees
-- onto its alternate value (the rotated art already prints that number).
-- No schema change -- version bump only.
UPDATE schema_version SET version = '1.62.22' WHERE id = 1;

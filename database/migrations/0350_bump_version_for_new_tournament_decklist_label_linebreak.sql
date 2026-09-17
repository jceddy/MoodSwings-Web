-- Reported live: put "Or paste your decklist" on its own line in the
-- New Tournament dialog's decklist fields, matching the existing
-- in-game duel-deck-submit-form-container's own label/textarea layout.
--
-- No schema change, just the version bump MaintenanceGate needs to see
-- this deploy as caught up with the code.
UPDATE schema_version SET version = '1.50.11' WHERE id = 1;

-- Reported live: same "Or paste your decklist" own-line fix as
-- migration 0350, applied to #tournament-deck-dialog's own decklist
-- fields too (the "Submit your deck" dialog shared by joining an open
-- tournament, accepting an invite, and editing your deck before the
-- tournament starts) -- migration 0350 only reached the New Tournament
-- dialog's own copy of this same markup.
--
-- No schema change, just the version bump MaintenanceGate needs to see
-- this deploy as caught up with the code.
UPDATE schema_version SET version = '1.50.12' WHERE id = 1;

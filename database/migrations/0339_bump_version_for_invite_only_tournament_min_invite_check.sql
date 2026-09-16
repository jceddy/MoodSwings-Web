-- The New Tournament dialog now blocks submission client-side when
-- registration_mode is invite_only and fewer than max_participants - 1
-- friends are checked (the creator already auto-joins as one of
-- max_participants' own seats, so anything less could never actually
-- fill the tournament). No backend/schema change -- purely a frontend
-- validation tweak -- just the version bump MaintenanceGate needs to see
-- this deploy as caught up with the code.
UPDATE schema_version SET version = '1.50.2' WHERE id = 1;

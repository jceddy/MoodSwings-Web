-- Adds a notification dot on the lobby's Achievements button (and a
-- browser toast) when an achievement has unlocked since the player last
-- opened the Achievements page, plus a "hide locked achievements" toggle
-- on that page itself. Both are pure client-side additions against the
-- already-existing GET /user/achievements response -- no schema change,
-- just the version bump MaintenanceGate needs to see this deploy as
-- caught up with the code.
UPDATE schema_version SET version = '1.51.3' WHERE id = 1;

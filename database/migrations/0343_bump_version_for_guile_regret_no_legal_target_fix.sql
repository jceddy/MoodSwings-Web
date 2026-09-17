-- Guile/Regret can now be played even when no legal target exists for
-- their mandatory "after playing this mood" effect (e.g. no opponent
-- has any mood in play yet) -- the effect simply fizzles instead of
-- blocking the whole play, the same tolerance MaliceEffect's own
-- optional target already had. See CardChoiceSchema's own
-- optional_if_no_targets docblock.
--
-- No schema change, just the version bump MaintenanceGate needs to see
-- this deploy as caught up with the code.
UPDATE schema_version SET version = '1.50.6' WHERE id = 1;

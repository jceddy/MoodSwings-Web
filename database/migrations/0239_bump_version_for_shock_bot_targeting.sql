-- Bot policy fix (reported live: "bots should choose an opponent's mood
-- to target with shock when playing it") -- Shock's own target_mood_ids
-- field is optional and wasn't in any "always fill" list, so the bot was
-- previously playing Shock as a plain 2-value mood with its own discard
-- ability never used at all. See BotPlayerService::shockTargetMoodIds()
-- for the fix. No schema change, just the version bump MaintenanceGate
-- needs to see this deploy as caught up with the code.
UPDATE schema_version SET version = '1.33.3' WHERE id = 1;

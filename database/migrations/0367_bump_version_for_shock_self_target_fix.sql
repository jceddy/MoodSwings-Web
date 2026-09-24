-- Reported live: "Shock should be able to target itself." Shock's own
-- printed text has no "other than this one" exclusion, and its printed
-- value (2) always qualifies for its own "value 3 or less" filter --
-- ShockEffect::afterPlaying() never checked a target against the card's
-- own id either, so the only real gap was CardChoiceSchema's 'shock'
-- entry missing 'includes_self' => true (the same schema-metadata bug
-- already fixed once each for Hate, Anger, Conviction, and Hostility's
-- second stage). No schema change -- just the version bump
-- MaintenanceGate needs to see this deploy as caught up with the code.
UPDATE schema_version SET version = '1.51.10' WHERE id = 1;

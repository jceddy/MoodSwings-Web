-- Follow-up to migration 0237's Tactical Bot speed tiers: removed the
-- New Game dialog's own colored "Tactical · up to Ns" badge (the
-- maintainer felt BotSage/BotSageQuick/BotSageDeep's own names already
-- say enough about relative speed) -- see web-static/js/game.js and
-- style.css. No schema change, just the version bump MaintenanceGate
-- needs to see this deploy as caught up with the code.
UPDATE schema_version SET version = '1.33.2' WHERE id = 1;

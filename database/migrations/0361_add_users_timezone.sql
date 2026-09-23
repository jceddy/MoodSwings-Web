-- Achievements' Night Owl/Early Bird ("...your local time") and Marathon
-- Session ("a single calendar day") were computing hour/date off the
-- server's own UTC (see Connection::get()'s SET time_zone = '+00:00'),
-- not the player's real local time -- reported live as "the timed
-- achievements aren't calculating local time correctly." The browser's
-- IANA identifier (e.g. 'America/Los_Angeles') is now sent as the
-- X-Timezone header on every apiRequest() call and kept in sync here by
-- AuthService::currentUser() on every authenticated request, same
-- treatment as sessions.last_seen_at. NULL until a browser running the
-- new app.js has made at least one authenticated request -- see
-- AchievementService::timezoneFor(), which falls back to UTC until then.
ALTER TABLE users
    ADD COLUMN timezone VARCHAR(64) NULL AFTER matchmaking_discoverable;

UPDATE schema_version SET version = '1.51.4' WHERE id = 1;

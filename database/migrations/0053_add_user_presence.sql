-- Online/presence indicator (issue #110): "online" is derived cheaply
-- from sessions.last_seen_at (already touched on every authenticated
-- request -- see AuthService::currentUser()/SessionRepository::touch())
-- rather than a new heartbeat/websocket mechanism -- a user counts as
-- online if any of their currently-valid sessions were active within
-- the last PresenceService::ONLINE_THRESHOLD_SECONDS. share_presence
-- lets a user opt out of sharing this signal entirely, surfaced to
-- others as a distinct "hidden" state (not conflated with "offline") on
-- the friends list and a game's own Players list -- defaults to 1
-- (shared) so every existing user's behavior is unchanged until they
-- explicitly opt out on the User info page.
ALTER TABLE users ADD COLUMN share_presence TINYINT(1) NOT NULL DEFAULT 1 AFTER phone_number;

UPDATE schema_version SET version = '1.4.0' WHERE id = 1;

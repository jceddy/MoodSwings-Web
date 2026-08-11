-- New user-facing feature: a full-viewport loading overlay shown while
-- the lobby/board's own initial data fetch (GET /games, GET /games/state,
-- GET /games/spectate/state, GET /games/log + GET /games/replay/state) is
-- in flight, so that moment doesn't look like a blank/broken page --
-- deliberately NOT shown for the routine 4-second poll or for an in-game
-- action, just the initial view load. Pure frontend (game/index.html,
-- style.css, game.js) -- no schema change. See "Loading overlay" in
-- web-static/README.md.
UPDATE schema_version SET version = '1.21.0' WHERE id = 1;

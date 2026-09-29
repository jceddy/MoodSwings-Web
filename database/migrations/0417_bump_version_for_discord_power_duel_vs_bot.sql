-- Issue #233 follow-up (reported live: "I want to be able to create a
-- power duel game with a bot opponent, as well"). Pure code change, no
-- schema change -- reuses GameService::createGame()'s own existing
-- botSavedDecklistId param (already wired for the web app's New Game
-- dialog) to submit a caller-chosen saved decklist for the bot's seat at
-- creation time, exactly the way a friend's own seat is normally filled
-- in afterward. Same "schema-version-only migration" pattern as every
-- other pure-code-change Discord follow-up in this arc (0387-0393,
-- 0410-0411, 0415-0416) -- see php-app/README.md.
UPDATE schema_version SET version = '1.57.2' WHERE id = 1;

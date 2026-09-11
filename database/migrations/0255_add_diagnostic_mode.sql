-- Diagnostic mode (issue reported live: "let's add a 'diagnostic mode'
-- checkbox when creating a game including one or more tactical bot(s) -
-- if diagnostic mode is enable, a button should be available to allow a
-- human player to view the bot(s) hand(s), as well as (if possible) a
-- button to show the 'reasoning' behind every play the bot has made
-- since the human player's previous play - the heuristics involved, the
-- play options considered, and the relative scoring assigned to those
-- considered options").
--
-- A per-game toggle, chosen once at creation like default_selections_mode
-- (migration 0087) -- only ever meaningful (and only ever offered by the
-- New Game dialog) for a game seating at least one Tactical Bot
-- (users.uses_tactical_ai, migration 0236); GameService::createGame()
-- silently no-ops it for every other game, the same "harmless no-op
-- outside its own narrow scope" convention allow_sideboarding/
-- best_of_three already established. Defaults to 0 (off) so every
-- existing/new game's behavior -- and its own game_events volume -- is
-- unchanged unless explicitly chosen at creation.
--
-- No new table needed for the "reasoning behind every play" half: a
-- Tactical Bot's search decision (once per turn) is logged as an
-- ordinary new game_events row (event_type 'tactical_bot_reasoning',
-- details carrying the candidates actually considered, their UCB1
-- visit/average-reward stats, and which cards the heuristic policy
-- excluded before the search ever saw them) -- the exact same
-- append-only, JSON-details table every other game action already logs
-- through, rather than a bespoke parallel history just for this.
ALTER TABLE games
    ADD COLUMN diagnostic_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER default_selections_mode;

UPDATE schema_version SET version = '1.34.0' WHERE id = 1;

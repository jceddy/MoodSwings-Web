-- Issue #417's own "Ability to choose to have the bot play first in bot
-- play" item, confirmed by the maintainer: a per-game toggle, chosen once
-- at game creation like default_selections_mode (migration 0087), that
-- makes a seated practice bot go first instead of leaving it to
-- resolveFirstPlayerId()'s own plain 50/50 random pick. Scoped
-- deliberately narrow -- only meaningful for a non-team format's own
-- initial coin flip (GameService::resolveFirstPlayerId()); Open/Closed
-- Team Play's own "who actually takes the opening turn" is a separate,
-- later turn_order decision never decided at game creation, and a
-- best-of-three draft match's own game 2/3 "loser decides" mechanic
-- keeps its current bot policy (never opts to go first) untouched. When
-- more than one bot is seated (a 3-4 player standard game), the one that
-- ends up going first is picked at random among just the bot seats.
-- Defaults to 0 (off) so every existing/new game's behavior is unchanged
-- unless explicitly chosen at creation, same reasoning
-- default_selections_mode's own default-off column already documents.
ALTER TABLE games
    ADD COLUMN bot_goes_first TINYINT(1) NOT NULL DEFAULT 0 AFTER default_selections_mode;

UPDATE schema_version SET version = '1.28.27' WHERE id = 1;

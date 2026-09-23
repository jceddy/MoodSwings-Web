-- Reported live: "Comeback Kid did not unlock" for a Sealed Deck match
-- won 2-1 after losing game 1. advanceDraftMatch() (draft_match_id --
-- Sealed Deck/Sealed Pool of the Day/Weekly Sealed Pool/Quick Draft/
-- Booster Draft/Rotisserie Draft) never called any AchievementService
-- hook on match completion, unlike advanceGameMatch() (game_match_id --
-- Duel/Team Play/Traditional), so Match Point/Match Maker/Grand
-- Champion/Comeback Kid/Flawless Victory were silently never checked for
-- any draft-family match. No schema change, just the version bump
-- MaintenanceGate needs to see this deploy as caught up with the code.
UPDATE schema_version SET version = '1.51.5' WHERE id = 1;

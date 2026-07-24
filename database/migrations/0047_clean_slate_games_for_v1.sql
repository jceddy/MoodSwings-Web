-- One-time cleanup for the 1.0.0 release: every game logged before issue
-- #240's "watch game replay" landed is missing data replay reconstruction
-- now depends on -- drawCard() didn't record which card was drawn
-- (BoardState's own $pendingDraws docblock), and suppression/effect-state
-- changes were never logged as their own events at all (see
-- $pendingSuppressionChanges/$pendingEffectStateChanges). A pre-existing
-- game can still be READ (its final game_cards state is intact), but
-- ReplayStateBuilder's reverse-derived genesis for one would silently be
-- wrong -- and any game still 'waiting'/'in_progress' from before this
-- release simply can't be continued correctly either, since GameService's
-- own event-logging shape changed under it mid-flight.
--
-- Accepted as fine (per explicit product decision, not a bug to work
-- around): every game in the system -- regardless of status -- is wiped
-- here, giving every deployment a clean slate the moment this migration
-- runs. This is why the version bumps all the way to 1.0.0 (see VERSION)
-- rather than another patch/minor bump like every other schema_version
-- update in this migrations/ directory -- it's the one release where
-- old gameplay history is deliberately not preserved.
--
-- user_lifetime_stats is the one exception, left untouched on purpose --
-- see its own migration 0042's docblock: "the whole point of 'lifetime'
-- is that it must survive old game data being cleaned up later." Also
-- untouched: users/sessions/email_verifications/friendships (accounts and
-- relationships aren't game data) and user_decklists (a saved decklist
-- isn't tied to any particular game either).
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE game_pending_decisions;
TRUNCATE TABLE game_pending_decision_batches;
TRUNCATE TABLE game_round_scores;
TRUNCATE TABLE game_events;
TRUNCATE TABLE game_team_decisions;
TRUNCATE TABLE game_initial_card_passes;
TRUNCATE TABLE game_cards;
TRUNCATE TABLE game_rounds;
TRUNCATE TABLE draft_round_picks;
TRUNCATE TABLE draft_winston_state;
TRUNCATE TABLE draft_grid_state;
TRUNCATE TABLE draft_match_players;
TRUNCATE TABLE draft_matches;
TRUNCATE TABLE game_players;
TRUNCATE TABLE games;
SET FOREIGN_KEY_CHECKS = 1;

UPDATE schema_version SET version = '1.0.0' WHERE id = 1;

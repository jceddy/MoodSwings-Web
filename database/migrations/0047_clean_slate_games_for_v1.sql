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
--
-- Deliberately DELETE, not TRUNCATE, and in explicit leaf-to-root FK
-- order rather than toggling FOREIGN_KEY_CHECKS -- TRUNCATE TABLE is
-- refused outright on a table any FOREIGN KEY still references (MySQL
-- error 1701), and on at least one production deployment
-- SET FOREIGN_KEY_CHECKS = 0 did not actually suppress that for TRUNCATE
-- (some clustered/replicated MySQL setups replicate TRUNCATE as DDL via
-- their own total-order isolation, bypassing session-level variables
-- entirely). Plain DELETE respects foreign keys the ordinary way, so
-- ordering the statements correctly works everywhere regardless of the
-- server's own replication topology.
--
-- games and game_players form a genuine cycle (game_players.game_id ->
-- games ON DELETE CASCADE; games.winner_game_player_id -> game_players ON
-- DELETE SET NULL), as do games and draft_matches
-- (games.draft_match_id -> draft_matches ON DELETE SET NULL) and
-- game_cards with itself (copied_card_id/suppression_source_game_card_id,
-- both ON DELETE SET NULL) -- the three UPDATE statements below null out
-- exactly those columns first so every DELETE that follows only ever
-- removes rows nothing outstanding still points to.
UPDATE games SET winner_game_player_id = NULL, draft_match_id = NULL;
UPDATE game_cards SET copied_card_id = NULL, suppression_source_game_card_id = NULL;

DELETE FROM game_pending_decisions;
DELETE FROM game_pending_decision_batches;
DELETE FROM game_round_scores;
DELETE FROM game_events;
DELETE FROM game_team_decisions;
DELETE FROM game_initial_card_passes;
DELETE FROM game_cards;
DELETE FROM game_rounds;
DELETE FROM draft_round_picks;
DELETE FROM draft_winston_state;
DELETE FROM draft_grid_state;
DELETE FROM draft_match_players;
DELETE FROM game_players;
DELETE FROM draft_matches;
DELETE FROM games;

UPDATE schema_version SET version = '1.0.0' WHERE id = 1;

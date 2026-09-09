-- Reported live: "the advance turn button is not showing up where I
-- wanted -- I want it to show up after scoring but before 'after
-- scoring' effects happen -- for example, if an opponent plays
-- recklessness and steals one of my boredom, I want to be able to see
-- the board State with their recklessness in play and my boredom on
-- their side before I move on to the next round".
--
-- "Pause at the start of your turn" (migration 0263) already fires at
-- the right MOMENT for its own preference check, but by the time it
-- shows anything, GameService::finishScoringAndAdvance() has already run
-- applyAfterScoringHooks() (Recklessness's own "give it back"/bottom-
-- and-draw among others) and created the new round -- so the board that
-- pause actually displays is always the AFTER-hooks state, never the
-- "just scored" moment described above. Migration 0263's own docblock
-- already anticipated exactly this ask ("...before/after scoring effects
-- happen -- sometimes even with the log text available it is difficult
-- to figure out for many users") but the implementation never actually
-- delivered the "before" half.
--
-- game_rounds.pre_after_scoring_event_id: the id of the last game_events
-- row that existed right before THIS round's own scoring/after-scoring
-- mutations began (GameService::latestEventId(), captured at the very
-- top of finishScoringAndAdvance(), before RoundScorer::score()/
-- applyAfterScoringHooks() ever run), carried onto the NEW round's own
-- row at INSERT time. GameService::buildGameState() uses it -- via
-- ReplayStateBuilder::stateAsOf() (issue #240's own "watch replay"
-- machinery, which already reconstructs an exact historical BoardState
-- purely from already-recorded game_events facts, no re-executed effect
-- code) -- to serve a FROZEN pre-hook board to whichever viewer is this
-- round's own current turn holder, for as long as
-- turn_pending_acknowledgment stays set. This is a personal viewing
-- gate, same as turn_pending_acknowledgment itself already is: every
-- OTHER player (and any bot) still sees, and can immediately act on, the
-- real, already-advanced board -- nothing about game_cards/game_players'
-- actual mutation timing changes here, only what ONE paused viewer's own
-- GET /games/state happens to render until they click Advance Turn.
--
-- NULL for Open/Closed Team Play's own separate round-transition path
-- (finishTeamScoringAndAdvance()/confirmTeamDecision()/
-- applyTurnOrderDecision(), a materially different, decision-gated
-- pipeline -- left as a known follow-up) and for Awe's "skip scoring
-- this round" path (skipScoringAndAdvance() -- nothing scored, so
-- nothing to freeze). buildGameState() simply falls back to today's
-- already-shipped behavior (a live board, same as before this
-- migration) whenever this column is NULL.
ALTER TABLE game_rounds
    ADD COLUMN pre_after_scoring_event_id BIGINT UNSIGNED DEFAULT NULL AFTER turn_pending_acknowledgment,
    ADD CONSTRAINT fk_game_rounds_pre_after_scoring_event FOREIGN KEY (pre_after_scoring_event_id) REFERENCES game_events (id) ON DELETE SET NULL;

UPDATE schema_version SET version = '1.39.2' WHERE id = 1;

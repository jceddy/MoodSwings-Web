-- Closes the race GameService::assertNoPendingDecision() couldn't close on
-- its own: it's a plain SELECT-then-INSERT, so two requests for the same
-- round (e.g. the same player's two open tabs, or a play racing a
-- respondToDecision() that itself uncovers a chained pending decision) can
-- both see "no pending decision" and both go on to create their own batch,
-- leaving two simultaneously-open batches for one round -- something the
-- rest of the code (which always assumes at most one) never expects and
-- has no way to recover from.
--
-- active_marker is NULL for every resolved batch (resolved_at IS NOT NULL)
-- and a constant 1 for the one batch, if any, still open -- a UNIQUE index
-- treats each NULL as distinct, so any number of resolved batches share a
-- round without conflict, but a second INSERT for a round that already has
-- an open batch collides on (game_round_id, active_marker) and fails
-- immediately, at the database level, rather than silently succeeding.
-- GameService::writePendingBatch() catches that specific duplicate-key
-- error and turns it into the same GameStateException
-- assertNoPendingDecision() already throws for the non-racing case.
ALTER TABLE game_pending_decision_batches
    ADD COLUMN active_marker TINYINT UNSIGNED GENERATED ALWAYS AS (IF(resolved_at IS NULL, 1, NULL)) STORED AFTER resolved_at,
    ADD UNIQUE KEY uq_pending_batches_one_open_per_round (game_round_id, active_marker);

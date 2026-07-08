-- Vulnerability: "While in play, this mood's value is 7 if a card was put
-- into the discard pile this round." This has to be a single flag on the
-- round itself, not something attached to any particular card's
-- effectState, since it needs to reflect *any* discard by *any* player,
-- including ones that happen before Vulnerability is even in play. Reset
-- to its default (false) automatically whenever a new round row is
-- inserted; set true by BoardState's discard-pile-adding methods and
-- persisted the same way pending_play_grants is, via GameService's
-- updateRoundTurnState(). See BoardState::discardedThisRound().
ALTER TABLE game_rounds
    ADD COLUMN discarded_this_round TINYINT(1) NOT NULL DEFAULT 0 AFTER pending_play_grants;

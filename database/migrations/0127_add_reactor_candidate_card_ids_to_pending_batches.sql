-- Fixes a real bug, caught live: Validation never reacted to Thrill's
-- own play when Thrill's own choice happened to return Validation
-- itself to hand (Thrill's "you may put any number of your other moods
-- into your hand" is exactly the kind of side effect that could do
-- this), even though Validation genuinely was in play at the moment
-- Thrill was played.
--
-- MoodPlayService::resolveAfterPlayingChain() snapshots, right before an
-- invocation's own afterPlaying()/resolveDecisions() can mutate
-- anything, every OTHER mood the acting player currently owns in play --
-- MoodEffect::reactToAnotherPlay()'s own candidates (Scorn, Validation).
-- continueAfterPlayingChain()/finishAfterPlayingChain() need that
-- PRE-mutation snapshot back later, possibly after a real request
-- round-trip (Duplicity's own "repeat again?" pause) -- exactly the same
-- shape of bug, and the same fix, migration 0107 already applied to
-- duplicity_eligible_sources for the same class of problem (a card
-- whose own afterPlaying() effect discards/moves something the chain
-- still needs to know about later).
--
-- The snapshot is carried as a plain PlayResult field, persisted here as
-- this new column whenever a PlayResult::pending() gets written to a
-- game_pending_decision_batches row (see GameService::writePendingBatch()),
-- and read back the exact same way duplicity_eligible_sources already is
-- before resuming via MoodPlayService::resolvePendingDecisions(). See
-- "Validation"/"Scorn" in php-app/README.md.
ALTER TABLE game_pending_decision_batches
    ADD COLUMN reactor_candidate_card_ids JSON NOT NULL DEFAULT (JSON_ARRAY()) AFTER duplicity_eligible_sources;

UPDATE schema_version SET version = '1.25.2' WHERE id = 1;

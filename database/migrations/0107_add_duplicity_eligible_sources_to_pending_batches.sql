-- Fixes a real bug: Duplicity never offered to repeat Anger's, Hate's,
-- or (when it happens to discard itself as part of its own color
-- cascade) Malice's own after-playing effect.
--
-- MoodPlayService::resolveAfterPlayingChain() snapshots, right before an
-- invocation's own afterPlaying()/resolveDecisions() can mutate
-- anything, how many independent Duplicity-effective sources the acting
-- player owns -- continueAfterPlayingChain() needs that PRE-mutation
-- count back later, possibly after a real request round-trip (an
-- opponent's own RequiresOpponentDecision pause). The old design stored
-- this snapshot on the played card's own BoardState effectState bag,
-- keyed by its own card id -- which broke the instant that same card
-- left play as a side effect of resolving its own effect (Anger/Hate
-- targeting themselves; Malice, whose color cascade can also discard
-- itself), since effectState lives on the card's own transient in-play
-- entry and is gone the moment it's discarded/bottom-decked.
--
-- The snapshot is now carried as a plain PlayResult field instead,
-- persisted here as this new column whenever a PlayResult::pending()
-- gets written to a game_pending_decision_batches row (see
-- GameService::writePendingBatch()), and read back the exact same way
-- invocation_choices/invocation_seq already are before resuming via
-- MoodPlayService::resolvePendingDecisions(). See "Duplicity" in
-- php-app/README.md.
ALTER TABLE game_pending_decision_batches
    ADD COLUMN duplicity_eligible_sources TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER invocation_choices;

UPDATE schema_version SET version = '1.20.3' WHERE id = 1;

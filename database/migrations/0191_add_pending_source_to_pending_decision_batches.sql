-- Issue #405 follow-up (reported live: "the other player should choose
-- the card from their hand to give to me -- it should not be random"):
-- an ATTACHED chaos effect can now pause and ask a specific player for a
-- real decision too (see ChaosRequiresOpponentDecision), the same way
-- the base printed card's own RequiresOpponentDecision already could --
-- rather than the uniformly-random simplification ChaosMoodEffect's own
-- class docblock previously documented as this pool's deliberate
-- default, for the handful of chaos effects (chaos_010/029/031/067/068/
-- 078/082/086/091/096) whose printed text has an identically-shaped
-- printed-card precedent already using RequiresOpponentDecision.
--
-- MoodPlayService::resolvePendingDecisions() needs to know, when resuming
-- a pause, whether to resolve it through the base effect's own
-- EffectRegistry or the attached chaos effect's own ChaosEffectRegistry
-- -- played_card_id alone can't disambiguate, since a single card can
-- carry BOTH a base RequiresOpponentDecision effect and an attached
-- chaos effect at once. Carried as a plain PlayResult field
-- ($pendingSource), persisted here the exact same way migrations
-- 0107/0127 already persisted duplicity_eligible_sources/
-- reactor_candidate_card_ids for the same "a snapshot the chain needs
-- back after a real request round-trip" reason. See
-- ChaosRequiresOpponentDecision's own docblock and php-app/README.md's
-- Chaos Draft section.
ALTER TABLE game_pending_decision_batches
    ADD COLUMN pending_source ENUM('effect', 'chaos_effect') NOT NULL DEFAULT 'effect' AFTER reactor_candidate_card_ids;

UPDATE schema_version SET version = '1.28.47' WHERE id = 1;

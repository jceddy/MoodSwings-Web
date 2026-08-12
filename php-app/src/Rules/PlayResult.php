<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * MoodPlayService::playMood()'s outcome: either the play (and every
 * chained Duplicity repeat of it) fully resolved, or it's paused waiting
 * on one or more PendingDecisionRequests -- see RequiresOpponentDecision.
 * GameService persists a new game_pending_decision_batches row (with its
 * $pendingDecisions as game_pending_decisions rows) whenever $isPending is
 * true, instead of advancing the turn.
 *
 * $invocationChoices is the exact PlayerChoices bag the paused invocation
 * itself was given -- GameService stores it as that new batch's own
 * invocation_choices verbatim (rather than trying to re-derive it, e.g.
 * from some fixed location in the top-level choices, which stopped being
 * possible once a Duplicity repeat's own choices could come from an
 * answered pending decision instead of a pre-submitted nested field --
 * see MoodPlayService::resolveDuplicityRepeatOffer()). Only meaningful
 * when $isPending is true.
 *
 * $duplicityEligibleSources is this invocation's own pre-mutation
 * Duplicity-repeat-eligible-source count (see
 * MoodPlayService::resolveAfterPlayingChain()'s own docblock for why it
 * has to be a snapshot taken before the effect's own mutation, not a
 * live recount later) -- GameService persists it as that same batch's
 * own duplicity_eligible_sources column, since continueAfterPlayingChain()
 * needs the value back once every pending decision in THIS batch
 * resolves, which can be a real request later (a genuinely different
 * request than the one that computed it -- see migration
 * 0107_add_duplicity_eligible_sources_to_pending_batches.sql). Carrying
 * it through PlayResult itself (rather than BoardState, where it used to
 * live keyed on the played card's own transient in-play effectState) is
 * what fixes a real bug: a card whose own afterPlaying() effect discards
 * or bottom-decks ITSELF (Anger, Hate, and Malice when it happens to
 * share a color with one of its own resolveDecisions() targets) used to
 * silently lose this snapshot the moment it left play, so Duplicity
 * never got offered a repeat of it at all.
 */
final class PlayResult
{
    /** @param PendingDecisionRequest[] $pendingDecisions */
    private function __construct(
        public readonly bool $isPending,
        public readonly array $pendingDecisions = [],
        public readonly ?int $playedCardId = null,
        public readonly int $invocationSeq = 0,
        public readonly ?PlayerChoices $invocationChoices = null,
        public readonly int $duplicityEligibleSources = 0,
    ) {
    }

    public static function complete(): self
    {
        return new self(isPending: false);
    }

    /** @param PendingDecisionRequest[] $pendingDecisions */
    public static function pending(array $pendingDecisions, int $playedCardId, int $invocationSeq, PlayerChoices $invocationChoices, int $duplicityEligibleSources): self
    {
        return new self(isPending: true, pendingDecisions: $pendingDecisions, playedCardId: $playedCardId, invocationSeq: $invocationSeq, invocationChoices: $invocationChoices, duplicityEligibleSources: $duplicityEligibleSources);
    }
}

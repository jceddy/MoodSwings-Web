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
    ) {
    }

    public static function complete(): self
    {
        return new self(isPending: false);
    }

    /** @param PendingDecisionRequest[] $pendingDecisions */
    public static function pending(array $pendingDecisions, int $playedCardId, int $invocationSeq, PlayerChoices $invocationChoices): self
    {
        return new self(isPending: true, pendingDecisions: $pendingDecisions, playedCardId: $playedCardId, invocationSeq: $invocationSeq, invocationChoices: $invocationChoices);
    }
}

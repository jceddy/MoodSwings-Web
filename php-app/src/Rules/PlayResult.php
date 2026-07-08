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
 */
final class PlayResult
{
    /** @param PendingDecisionRequest[] $pendingDecisions */
    private function __construct(
        public readonly bool $isPending,
        public readonly array $pendingDecisions = [],
        public readonly ?int $playedCardId = null,
        public readonly int $invocationSeq = 0,
    ) {
    }

    public static function complete(): self
    {
        return new self(isPending: false);
    }

    /** @param PendingDecisionRequest[] $pendingDecisions */
    public static function pending(array $pendingDecisions, int $playedCardId, int $invocationSeq): self
    {
        return new self(isPending: true, pendingDecisions: $pendingDecisions, playedCardId: $playedCardId, invocationSeq: $invocationSeq);
    }
}

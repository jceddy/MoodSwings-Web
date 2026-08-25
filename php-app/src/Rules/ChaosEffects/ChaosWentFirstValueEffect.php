<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * chaos_004/chaos_104: "While in play, this mood's value is X if you
 * (didn't) go first this round." $wantWentFirst selects which direction
 * (true for chaos_104's "went first", false for chaos_004's "didn't go
 * first").
 */
final class ChaosWentFirstValueEffect extends AbstractChaosMoodEffect
{
    public function __construct(
        private readonly bool $wantWentFirst,
        private readonly int $valueIfMet,
    ) {
    }

    public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
    {
        $wentFirst = $state->roundFirstPlayerId() === $state->ownerOf($cardId);

        return $wentFirst === $this->wantWentFirst ? $this->valueIfMet : $incomingValue;
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use Closure;
use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * chaos_074/117/130: "While in play, this mood's value increases by 1 for
 * each <thing>." Unlike the threshold-family effects (a fixed override
 * once a condition is met), these are purely additive on top of whatever
 * the printed-value pipeline already produced -- see ChaosMoodEffect's
 * own docblock for why $incomingValue is the correct starting point
 * rather than the card's raw baseValue.
 */
final class ChaosAdditiveCountValueEffect extends AbstractChaosMoodEffect
{
    /** @param Closure(BoardState, int): int $counter */
    public function __construct(
        private readonly Closure $counter,
        private readonly int $perCount = 1,
    ) {
    }

    public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
    {
        return $incomingValue + $this->perCount * ($this->counter)($state, $cardId);
    }
}

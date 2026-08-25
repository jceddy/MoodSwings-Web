<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use Closure;
use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Covers every "While in play, this mood's value is X if <condition>"
 * chaos effect whose condition is too one-off to justify its own
 * dedicated parameterized class (unlike, say,
 * ChaosPairedColorThresholdEffect's very common two-color pattern) --
 * the board-state check itself is simply handed in as a closure at
 * registration time (see ChaosDefaultEffectRegistry), the same "one
 * generic shape, condition supplied by the caller" approach
 * ChaosAdditiveCountValueEffect takes for the additive family.
 */
final class ChaosConditionalValueEffect extends AbstractChaosMoodEffect
{
    /** @param Closure(BoardState, int): bool $condition */
    public function __construct(
        private readonly Closure $condition,
        private readonly int $valueIfMet,
    ) {
    }

    public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
    {
        return ($this->condition)($state, $cardId) ? $this->valueIfMet : $incomingValue;
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Chaos Draft (issue #405) analog of Effects/PairedColorThresholdEffect.php:
 * covers the "While in play, this mood's value is X if there are two or
 * more <color> and/or <color> moods" family of chaos effects with one
 * parameterized implementation. Unlike the base-card version, this reads
 * an incoming (already-computed) value from the printed-value pipeline --
 * see ChaosMoodEffect's own docblock -- so the override, when the
 * threshold is met, replaces whatever the card's own printed ability
 * already produced, exactly like the plain-card version replaces
 * baseValue.
 */
final class ChaosPairedColorThresholdEffect extends AbstractChaosMoodEffect
{
    public function __construct(
        private readonly string $colorA,
        private readonly string $colorB,
        private readonly int $valueIfMet,
        private readonly int $threshold = 2,
    ) {
    }

    public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
    {
        $count = 0;
        foreach ($state->moodsInPlay() as $mood) {
            if (in_array($state->colorOf($mood->cardId), [$this->colorA, $this->colorB], true)) {
                $count++;
            }
        }

        return $count >= $this->threshold ? $this->valueIfMet : $incomingValue;
    }
}

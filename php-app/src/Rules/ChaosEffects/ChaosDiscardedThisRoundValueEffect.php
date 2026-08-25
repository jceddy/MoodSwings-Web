<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * chaos_132: "While in play, this mood's value is 7 if a card was put
 * into the discard pile this round." Chaos Draft analog of
 * Effects/VulnerabilityEffect.php's identical printed text.
 */
final class ChaosDiscardedThisRoundValueEffect extends AbstractChaosMoodEffect
{
    public function __construct(private readonly int $valueIfMet)
    {
    }

    public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
    {
        return $state->discardedThisRound() ? $this->valueIfMet : $incomingValue;
    }
}

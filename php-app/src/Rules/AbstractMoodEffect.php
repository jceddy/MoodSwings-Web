<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * Default, no-op implementations of the three ability timings. Most cards
 * only have one or two of the three abilities, so concrete effect classes
 * extend this and override only what they need.
 */
abstract class AbstractMoodEffect implements MoodEffect
{
    public function canPayToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): bool
    {
        return true;
    }

    public function payToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
    }

    public function computeValue(BoardState $state, int $cardId): int
    {
        return $state->catalogRow($state->effectiveCardId($cardId))['baseValue'];
    }

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * Default, no-op implementations of ChaosMoodEffect's own two hooks --
 * most chaos effects only have one or the other (chaos_effects.shape is
 * either 'after_playing' or 'while_in_play', never both), so a concrete
 * implementation extends this and overrides only the one it needs.
 */
abstract class AbstractChaosMoodEffect implements ChaosMoodEffect
{
    public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
    {
        return $incomingValue;
    }

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
    }
}

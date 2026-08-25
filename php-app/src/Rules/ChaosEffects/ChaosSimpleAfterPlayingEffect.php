<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use Closure;
use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * A thin afterPlaying() wrapper for the handful of chaos effects whose
 * whole behavior is one or two BoardState calls with no reusable shape of
 * their own (e.g. chaos_032's plain "draw a card") -- registered directly
 * with a closure in ChaosDefaultEffectRegistry rather than getting a
 * whole dedicated one-line class file.
 */
final class ChaosSimpleAfterPlayingEffect extends AbstractChaosMoodEffect
{
    /** @param Closure(BoardState, int, int, PlayerChoices): void $action */
    public function __construct(private readonly Closure $action)
    {
    }

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        ($this->action)($state, $cardId, $playerId, $choices);
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/** chaos_124 (rare, while_in_play): "You may play an additional mood during each of your turns (including the turn you play this mood)." */
final class Chaos124Effect extends AbstractChaosMoodEffect
{
    public function perpetualTurnStartGrants(BoardState $state, int $cardId, int $ownerId): array
    {
        return [[]];
    }
}

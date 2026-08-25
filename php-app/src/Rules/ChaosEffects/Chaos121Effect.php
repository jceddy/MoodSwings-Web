<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/** chaos_121 (rare, while_in_play): "During each of your turns (including the turn you play this mood), you may play an additional mood from the discard pile if it shares a color with one of your moods." */
final class Chaos121Effect extends AbstractChaosMoodEffect
{
    public function perpetualTurnStartGrants(BoardState $state, int $cardId, int $ownerId): array
    {
        return [['type' => 'shares_color_with_your_moods', 'source' => 'discard']];
    }
}

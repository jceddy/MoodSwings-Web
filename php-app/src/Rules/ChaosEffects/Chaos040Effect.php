<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/** chaos_040 (mythic, while_in_play): "Each time an opponent plays a mood, draw a card." */
final class Chaos040Effect extends AbstractChaosMoodEffect
{
    public function onMoodPlayed(BoardState $state, int $cardId, int $ownerId, int $playedByPlayerId, int $playedCardId): void
    {
        if ($playedByPlayerId !== $ownerId && !$state->isTeammate($ownerId, $playedByPlayerId)) {
            $state->drawCard($ownerId);
        }
    }
}

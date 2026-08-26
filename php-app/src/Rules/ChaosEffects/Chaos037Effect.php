<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/** chaos_037 (mythic, while_in_play): "Each time you play another mood, draw a card." */
final class Chaos037Effect extends AbstractChaosMoodEffect
{
    public function onMoodPlayed(BoardState $state, int $cardId, int $ownerId, int $playedByPlayerId, int $playedCardId): void
    {
        if ($playedByPlayerId === $ownerId && $playedCardId !== $cardId) {
            $state->drawCard($ownerId);
        }
    }
}

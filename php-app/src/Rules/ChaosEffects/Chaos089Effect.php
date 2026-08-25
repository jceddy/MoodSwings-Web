<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/** chaos_089 (mythic, while_in_play): "Whenever another one of your moods is put into the discard pile, put X red moods with value 1 named Tedium into play, where X is the value of that mood." */
final class Chaos089Effect extends AbstractChaosMoodEffect
{
    private const TOKEN_CATALOG_CARD_ID = 137;

    public function onMoodDiscarded(BoardState $state, int $cardId, int $ownerId, int $discardedCardId, int $discardedOwnerId, int $discardedValue): void
    {
        if ($discardedCardId === $cardId || $discardedOwnerId !== $ownerId) {
            return;
        }
        for ($i = 0; $i < $discardedValue; $i++) {
            $state->spawnMoodInPlay(self::TOKEN_CATALOG_CARD_ID, $ownerId);
        }
    }
}

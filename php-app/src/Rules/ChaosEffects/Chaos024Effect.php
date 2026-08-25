<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/** chaos_024 (mythic, while_in_play): "Each time a mood becomes suppressed, put a white mood with value 1 named Smugness into play." */
final class Chaos024Effect extends AbstractChaosMoodEffect
{
    private const TOKEN_CATALOG_CARD_ID = 134;

    public function onMoodSuppressed(BoardState $state, int $cardId, int $ownerId, int $suppressedCardId): void
    {
        $state->spawnMoodInPlay(self::TOKEN_CATALOG_CARD_ID, $ownerId);
    }
}

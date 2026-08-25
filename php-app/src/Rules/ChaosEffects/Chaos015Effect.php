<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/** chaos_015 (rare, while_in_play): "At the beginning of each round, if you go first this round, put a white mood with value 1 named Smugness into play." */
final class Chaos015Effect extends AbstractChaosMoodEffect
{
    private const TOKEN_CATALOG_CARD_ID = 134;

    public function roundStartHook(BoardState $state, int $cardId, int $ownerId): void
    {
        if ($state->roundFirstPlayerId() === $ownerId) {
            $state->spawnMoodInPlay(self::TOKEN_CATALOG_CARD_ID, $ownerId);
        }
    }
}

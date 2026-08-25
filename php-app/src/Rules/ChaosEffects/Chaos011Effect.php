<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/** chaos_011 (uncommon, while_in_play): "If this mood moves from play to the discard pile, put two white moods with value 1 named Smugness into play." Fires only when the discarded mood IS this effect's own card. */
final class Chaos011Effect extends AbstractChaosMoodEffect
{
    private const TOKEN_CATALOG_CARD_ID = 134;

    public function onMoodDiscarded(BoardState $state, int $cardId, int $ownerId, int $discardedCardId, int $discardedOwnerId, int $discardedValue): void
    {
        if ($discardedCardId !== $cardId) {
            return;
        }

        $state->spawnMoodInPlay(self::TOKEN_CATALOG_CARD_ID, $ownerId);
        $state->spawnMoodInPlay(self::TOKEN_CATALOG_CARD_ID, $ownerId);
    }
}

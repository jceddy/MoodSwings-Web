<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosLoopShortcut;

/**
 * chaos_024 (mythic, while_in_play): "Each time a mood becomes
 * suppressed, put a white mood with value 1 named Smugness into play."
 * Registered on ChaosLoopShortcut::REGISTERED_EFFECT_KEYS (issue #192
 * follow-up): only matters if some loop variant also suppresses a mood
 * every cycle -- not confirmed for any of the three known loops today,
 * but registered as a watch-item for the same reason chaos_011 is.
 */
final class Chaos024Effect extends AbstractChaosMoodEffect
{
    private const TOKEN_CATALOG_CARD_ID = 134;

    public function onMoodSuppressed(BoardState $state, int $cardId, int $ownerId, int $suppressedCardId): void
    {
        $state->spawnMoodInPlay(self::TOKEN_CATALOG_CARD_ID, $ownerId);
        $state->registerChaosEffectFired('chaos_024');
    }

    public function loopShortcut(BoardState $state, int $cardId, int $ownerId): ?ChaosLoopShortcut
    {
        return ChaosLoopShortcut::token(self::TOKEN_CATALOG_CARD_ID, $ownerId, 'Smugness');
    }
}

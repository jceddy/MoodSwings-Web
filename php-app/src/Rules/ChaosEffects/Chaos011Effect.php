<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosLoopShortcut;

/**
 * chaos_011 (uncommon, while_in_play): "If this mood moves from play to
 * the discard pile, put two white moods with value 1 named Smugness
 * into play." Fires only when the discarded mood IS this effect's own
 * card. Registered on ChaosLoopShortcut::REGISTERED_EFFECT_KEYS (issue
 * #192 follow-up): only matters if this happens to be attached to a
 * card a loop discards on every cycle -- none of the three known loops
 * discard Thrill/Fear/Nostalgia themselves today (only Angst's own
 * target), but a future combo or draft outcome could.
 */
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
        $state->registerChaosEffectFired('chaos_011');
    }

    public function loopShortcut(BoardState $state, int $cardId, int $ownerId): ?ChaosLoopShortcut
    {
        return ChaosLoopShortcut::token(self::TOKEN_CATALOG_CARD_ID, $ownerId, 'Smugness');
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosLoopShortcut;

/**
 * chaos_089 (mythic, while_in_play): "Whenever another one of your moods
 * is put into the discard pile, put X red moods with value 1 named
 * Tedium into play, where X is the value of that mood." Registered on
 * ChaosLoopShortcut::REGISTERED_EFFECT_KEYS (issue #192 follow-up): the
 * discard step both Angst-involving loops (Thrill->Angst->Nostalgia->
 * Thrill, and the minimal Fear+Angst loop) perform every cycle fires
 * this, minting an uncapped, value-scaled supply of tokens while making
 * BoardState::turnStateSignature() different every cycle. The shortcut
 * itself is a flat player-chosen count (ChaosLoopShortcut's own cap),
 * not a re-simulation of X-per-firing scaling -- see that class's own
 * docblock.
 */
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
        if ($discardedValue > 0) {
            $state->registerChaosEffectFired('chaos_089');
        }
    }

    public function loopShortcut(BoardState $state, int $cardId, int $ownerId): ?ChaosLoopShortcut
    {
        return ChaosLoopShortcut::token(self::TOKEN_CATALOG_CARD_ID, $ownerId, 'Tedium');
    }
}

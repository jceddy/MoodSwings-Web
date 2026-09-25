<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosLoopShortcut;

/**
 * chaos_037 (mythic, while_in_play): "Each time you play another mood,
 * draw a card." Registered on ChaosLoopShortcut::REGISTERED_EFFECT_KEYS
 * (issue #192 follow-up): no value/color condition at all, so this fires
 * on literally every cycle of every known same-turn loop, pulling a real
 * card out of the deck each time -- bounded only by deck size, but a
 * sustained loop could draw the entire deck in one turn, all while
 * BoardState::turnStateSignature() looks different every cycle (a fresh
 * card just entered hand) so the ordinary exact-signature detector never
 * sees a repeat.
 */
final class Chaos037Effect extends AbstractChaosMoodEffect
{
    public function onMoodPlayed(BoardState $state, int $cardId, int $ownerId, int $playedByPlayerId, int $playedCardId): void
    {
        if ($playedByPlayerId === $ownerId && $playedCardId !== $cardId) {
            $state->drawCard($ownerId);
            $state->registerChaosEffectFired('chaos_037');
        }
    }

    public function loopShortcut(BoardState $state, int $cardId, int $ownerId): ?ChaosLoopShortcut
    {
        return ChaosLoopShortcut::draw($ownerId, count($state->deck($ownerId)));
    }
}

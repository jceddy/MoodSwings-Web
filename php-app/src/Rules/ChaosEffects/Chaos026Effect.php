<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosLoopShortcut;

/**
 * chaos_026 (mythic, while_in_play): "Each time you play another mood
 * with a 0 or 1 in its top right corner, put a white mood with value 1
 * named Smugness into play." Registered on ChaosLoopShortcut::REGISTERED_EFFECT_KEYS
 * (issue #192 follow-up): Thrill (1)/Fear (0)/Nostalgia (0) all qualify,
 * so this fires on literally every cycle of all three known same-turn
 * loops (Thrill<->Fear, Thrill->Angst->Nostalgia->Thrill, Fear+Angst) --
 * no discard needed at all, just the ordinary replay -- minting an
 * uncapped supply of tokens while making BoardState::turnStateSignature()
 * different every cycle. See registerChaosEffectFired()'s own docblock
 * for why the count only increments once the qualifying-value check
 * below has already passed.
 */
final class Chaos026Effect extends AbstractChaosMoodEffect
{
    private const TOKEN_CATALOG_CARD_ID = 134;
    private const QUALIFYING_VALUES = [0, 1];

    public function onMoodPlayed(BoardState $state, int $cardId, int $ownerId, int $playedByPlayerId, int $playedCardId): void
    {
        if ($playedByPlayerId !== $ownerId || $playedCardId === $cardId) {
            return;
        }
        $baseValue = $state->catalogRow($state->effectiveCardId($playedCardId))['baseValue'];
        if (in_array($baseValue, self::QUALIFYING_VALUES, true)) {
            $state->spawnMoodInPlay(self::TOKEN_CATALOG_CARD_ID, $ownerId);
            $state->registerChaosEffectFired('chaos_026');
        }
    }

    public function loopShortcut(BoardState $state, int $cardId, int $ownerId): ?ChaosLoopShortcut
    {
        return ChaosLoopShortcut::token(self::TOKEN_CATALOG_CARD_ID, $ownerId, 'Smugness');
    }
}

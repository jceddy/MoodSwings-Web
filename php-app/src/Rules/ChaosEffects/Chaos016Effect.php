<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosLoopShortcut;

/**
 * chaos_016 (mythic, while_in_play): "Each time a mood with dice in its
 * lower left corner enters play, put a white mood with value 1 named
 * Smugness into play." A "dice" mood is one with a printed alt value
 * (catalogRow's own 'altValue', non-null) -- see the 'has_dice_value'
 * filter documented on CardChoiceSchema. Registered on
 * ChaosLoopShortcut::REGISTERED_EFFECT_KEYS (issue #192 follow-up): none
 * of Thrill/Fear/Angst/Nostalgia carry a dice value today, so this
 * doesn't combo with the three known loops, but is registered as a
 * watch-item for any dice-valued card a future loop might involve.
 */
final class Chaos016Effect extends AbstractChaosMoodEffect
{
    private const TOKEN_CATALOG_CARD_ID = 134;

    public function onMoodPlayed(BoardState $state, int $cardId, int $ownerId, int $playedByPlayerId, int $playedCardId): void
    {
        if ($state->catalogRow($state->effectiveCardId($playedCardId))['altValue'] !== null) {
            $state->spawnMoodInPlay(self::TOKEN_CATALOG_CARD_ID, $ownerId);
            $state->registerChaosEffectFired('chaos_016');
        }
    }

    public function loopShortcut(BoardState $state, int $cardId, int $ownerId): ?ChaosLoopShortcut
    {
        return ChaosLoopShortcut::token(self::TOKEN_CATALOG_CARD_ID, $ownerId, 'Smugness');
    }
}

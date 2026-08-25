<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/** chaos_016 (mythic, while_in_play): "Each time a mood with dice in its lower left corner enters play, put a white mood with value 1 named Smugness into play." A "dice" mood is one with a printed alt value (catalogRow's own 'altValue', non-null) -- see the 'has_dice_value' filter documented on CardChoiceSchema. */
final class Chaos016Effect extends AbstractChaosMoodEffect
{
    private const TOKEN_CATALOG_CARD_ID = 134;

    public function onMoodPlayed(BoardState $state, int $cardId, int $ownerId, int $playedByPlayerId, int $playedCardId): void
    {
        if ($state->catalogRow($state->effectiveCardId($playedCardId))['altValue'] !== null) {
            $state->spawnMoodInPlay(self::TOKEN_CATALOG_CARD_ID, $ownerId);
        }
    }
}

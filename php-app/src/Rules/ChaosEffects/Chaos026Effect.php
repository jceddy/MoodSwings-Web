<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/** chaos_026 (mythic, while_in_play): "Each time you play another mood with a 0 or 1 in its top right corner, put a white mood with value 1 named Smugness into play." */
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
        }
    }
}

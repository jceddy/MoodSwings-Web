<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Neutrality: "While in play -- This mood's value increases by 2 for each other mood with no color." Any
 * owner's moods, judged by current color (Imagination makes a colorless mood a color, so it stops counting).
 */
final class NeutralityEffect extends AbstractMoodEffect
{
    private const PER_COLORLESS_MOOD = 2;

    public function computeValue(BoardState $state, int $cardId): int
    {
        $colorless = 0;
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $state->colorOf($mood->cardId) === 'colorless') {
                $colorless++;
            }
        }

        return $state->catalogRow($state->effectiveCardId($cardId))['baseValue'] + self::PER_COLORLESS_MOOD * $colorless;
    }
}

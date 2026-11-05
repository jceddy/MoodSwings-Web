<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Emptiness: "While in play -- This mood's value increases by 1 for each color among all moods. (Colorless
 * moods have no color.)" Every player's moods count, by current color (under Imagination every mood is one
 * color, so the count is 1); Emptiness is colorless so it never counts itself.
 */
final class EmptinessEffect extends AbstractMoodEffect
{
    public function computeValue(BoardState $state, int $cardId): int
    {
        $colors = [];
        foreach ($state->moodsInPlay() as $mood) {
            $color = $state->colorOf($mood->cardId);
            if ($color !== 'colorless') {
                $colors[$color] = true;
            }
        }

        return $state->catalogRow($state->effectiveCardId($cardId))['baseValue'] + count($colors);
    }
}

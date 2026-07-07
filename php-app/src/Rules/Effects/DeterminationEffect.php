<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Determination: "While in play, this mood's value is 6 if there are
 * three or more moods that share a color." Unlike Bitterness (which cares
 * which color(s) are most common), this only needs to know whether *any*
 * color reaches the threshold.
 */
final class DeterminationEffect extends AbstractMoodEffect
{
    private const MINIMUM_SHARED = 3;

    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));

        $counts = [];
        foreach ($state->moodsInPlay() as $mood) {
            $color = $state->colorOf($mood->cardId);
            $counts[$color] = ($counts[$color] ?? 0) + 1;
        }

        return ($counts !== [] && max($counts) >= self::MINIMUM_SHARED) ? $row['altValue'] : $row['baseValue'];
    }
}

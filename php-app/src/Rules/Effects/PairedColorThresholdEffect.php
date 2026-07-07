<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Covers the very common "While in play, this mood's value is X if there
 * are two or more <color> and/or <color> moods" pattern (Ambivalence,
 * Discipline, Loyalty, Obsession, Disgust, Frustration, Disregard,
 * Enjoyment, Pity, Excitement, and others) with one parameterized
 * implementation instead of ten near-identical bespoke classes. The
 * threshold condition counts moods of *either* color combined -- per the
 * card notes, two of one color, two of the other, or one of each all
 * qualify.
 */
final class PairedColorThresholdEffect extends AbstractMoodEffect
{
    public function __construct(
        private readonly string $colorA,
        private readonly string $colorB,
        private readonly int $threshold = 2,
    ) {
    }

    public function computeValue(BoardState $state, int $cardId): int
    {
        $count = 0;
        foreach ($state->moodsInPlay() as $mood) {
            if (in_array($state->colorOf($mood->cardId), [$this->colorA, $this->colorB], true)) {
                $count++;
            }
        }

        $row = $state->catalogRow($state->effectiveCardId($cardId));

        return $count >= $this->threshold ? $row['altValue'] : $row['baseValue'];
    }
}

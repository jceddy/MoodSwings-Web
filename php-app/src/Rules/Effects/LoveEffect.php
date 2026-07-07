<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Love: "While in play, this mood's value is 12 if there's a white mood,
 * a blue mood, a black mood, a red mood, and a green mood, including this
 * one." (The Headliner treatment is a mechanically identical alternate-art
 * printing, not a separate card -- no separate effect_key needed.)
 */
final class LoveEffect extends AbstractMoodEffect
{
    private const ALL_COLORS = ['white', 'blue', 'black', 'red', 'green'];

    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));

        $colorsPresent = [];
        foreach ($state->moodsInPlay() as $mood) {
            $colorsPresent[$state->colorOf($mood->cardId)] = true;
        }

        foreach (self::ALL_COLORS as $color) {
            if (!isset($colorsPresent[$color])) {
                return $row['baseValue'];
            }
        }

        return $row['altValue'];
    }
}

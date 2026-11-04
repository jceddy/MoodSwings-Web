<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Solidarity: "While in play -- This mood's value increases by 1 for each other mood with a 2 in
 * its top right corner." Counts every other mood in play, whoever owns it, by its printed number
 * (so a mood showing a different value right now still counts as the 2 it prints).
 */
final class SolidarityEffect extends AbstractMoodEffect
{
    private const NUMBER = 2;

    public function computeValue(BoardState $state, int $cardId): int
    {
        $count = 0;
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && CustomEffectSupport::printedNumber($state, $mood->cardId) === self::NUMBER) {
                $count++;
            }
        }

        return $state->catalogRow($state->effectiveCardId($cardId))['baseValue'] + $count;
    }
}

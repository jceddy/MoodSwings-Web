<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/** Euphoria: "While in play, this mood's value increases by 1 for each mood in play, including itself and other players' moods." */
final class EuphoriaEffect extends AbstractMoodEffect
{
    private const VALUE_PER_MOOD = 1;

    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));

        return $row['baseValue'] + self::VALUE_PER_MOOD * count($state->moodsInPlay());
    }
}

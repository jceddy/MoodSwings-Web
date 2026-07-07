<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/** Sadness: "While in play, this mood's value increases by 2 for each card in the discard pile." */
final class SadnessEffect extends AbstractMoodEffect
{
    private const VALUE_PER_DISCARDED_CARD = 2;

    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));

        return $row['baseValue'] + self::VALUE_PER_DISCARDED_CARD * count($state->discardPile());
    }
}

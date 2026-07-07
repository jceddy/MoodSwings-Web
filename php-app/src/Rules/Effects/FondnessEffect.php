<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/** Fondness: "While in play, this mood's value is 7 if each player has three or more moods." */
final class FondnessEffect extends AbstractMoodEffect
{
    private const MINIMUM_MOODS = 3;

    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));

        foreach ($state->playerOrder() as $playerId) {
            if (count($state->moodsOwnedBy($playerId)) < self::MINIMUM_MOODS) {
                return $row['baseValue'];
            }
        }

        return $row['altValue'];
    }
}

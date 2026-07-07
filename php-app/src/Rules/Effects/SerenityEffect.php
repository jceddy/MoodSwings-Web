<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/** Serenity: "While in play, this mood's value is 6 if you have an even number of moods, including this one." */
final class SerenityEffect extends AbstractMoodEffect
{
    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));
        $ownerId = $state->ownerOf($cardId);

        return count($state->moodsOwnedBy($ownerId)) % 2 === 0 ? $row['altValue'] : $row['baseValue'];
    }
}

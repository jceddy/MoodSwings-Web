<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/** Tranquility: "While in play, this mood's value is 6 if you have an odd number of moods, including this one." The mirror image of Serenity. */
final class TranquilityEffect extends AbstractMoodEffect
{
    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));
        $ownerId = $state->ownerOf($cardId);

        return count($state->moodsOwnedBy($ownerId)) % 2 !== 0 ? $row['altValue'] : $row['baseValue'];
    }
}

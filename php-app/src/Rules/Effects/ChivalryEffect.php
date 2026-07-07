<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/** Chivalry: "While in play, this mood's value is 5 if you didn't go first this round." */
final class ChivalryEffect extends AbstractMoodEffect
{
    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));
        $ownerId = $state->ownerOf($cardId);

        return $state->roundFirstPlayerId() !== $ownerId ? $row['altValue'] : $row['baseValue'];
    }
}

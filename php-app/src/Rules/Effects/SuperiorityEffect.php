<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/** Superiority: "While in play, this mood's value is 7 if you have more moods than each other player." */
final class SuperiorityEffect extends AbstractMoodEffect
{
    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));
        $ownerId = $state->ownerOf($cardId);
        $ownerCount = count($state->moodsOwnedBy($ownerId));

        foreach ($state->playerOrder() as $playerId) {
            if ($playerId !== $ownerId && count($state->moodsOwnedBy($playerId)) >= $ownerCount) {
                return $row['baseValue'];
            }
        }

        return $row['altValue'];
    }
}

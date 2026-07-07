<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/** Animosity: "While in play, this mood's value is 5 if any opponent has three or more cards in hand." */
final class AnimosityEffect extends AbstractMoodEffect
{
    private const MINIMUM_HAND_SIZE = 3;

    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));
        $ownerId = $state->ownerOf($cardId);

        foreach ($state->playerOrder() as $playerId) {
            if ($playerId !== $ownerId && count($state->hand($playerId)) >= self::MINIMUM_HAND_SIZE) {
                return $row['altValue'];
            }
        }

        return $row['baseValue'];
    }
}

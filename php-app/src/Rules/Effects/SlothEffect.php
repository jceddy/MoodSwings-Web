<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/** Sloth: "While in play, this mood's value increases by 1 for each card in your hand." */
final class SlothEffect extends AbstractMoodEffect
{
    private const VALUE_PER_HAND_CARD = 1;

    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));
        $ownerId = $state->ownerOf($cardId);

        return $row['baseValue'] + self::VALUE_PER_HAND_CARD * count($state->hand($ownerId));
    }
}

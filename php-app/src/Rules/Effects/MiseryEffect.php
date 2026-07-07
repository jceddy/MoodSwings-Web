<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Misery: "While in play, this mood's value is 8 if there are two or
 * more cards in the discard pile that share a color." Like Determination
 * (which checks moods in play), but counts the discard pile instead.
 */
final class MiseryEffect extends AbstractMoodEffect
{
    private const MINIMUM_SHARED = 2;

    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));

        $counts = [];
        foreach ($state->discardPile() as $discardedCardId) {
            $color = $state->colorOf($discardedCardId);
            $counts[$color] = ($counts[$color] ?? 0) + 1;
        }

        return ($counts !== [] && max($counts) >= self::MINIMUM_SHARED) ? $row['altValue'] : $row['baseValue'];
    }
}

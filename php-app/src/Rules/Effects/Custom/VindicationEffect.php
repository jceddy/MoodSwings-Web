<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Vindication: "While in play -- This mood's value increases by 2 for each round you have won." Round wins
 * are BoardState::roundWinsOf() -- a round that awards two wins (Corruption) counts twice, and in a team
 * format "you" is your team.
 */
final class VindicationEffect extends AbstractMoodEffect
{
    private const PER_ROUND_WON = 2;

    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));

        return $row['baseValue'] + self::PER_ROUND_WON * $state->roundWinsOf($state->ownerOf($cardId));
    }
}

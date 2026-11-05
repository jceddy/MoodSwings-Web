<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Competitiveness: "While in play -- This mood's value is 1 more than the highest value among your
 * opponents' moods that don't have this ability. (If there are no such moods, this mood's value is 1.)"
 * Opposing moods with this same ability (a Competitiveness, or a Creativity copy of one) are left out of
 * the count -- that is what keeps two of them from reading each other's value for ever, and it means both
 * settle on the same value.
 */
final class CompetitivenessEffect extends AbstractMoodEffect
{
    public function computeValue(BoardState $state, int $cardId): int
    {
        $opponentIds = CustomEffectSupport::opponentIds($state, $state->ownerOf($cardId));

        $highest = 0;
        foreach ($state->moodsInPlay() as $mood) {
            if (!in_array($mood->ownerId, $opponentIds, true)) {
                continue;
            }
            if ($state->catalogRow($state->effectiveCardId($mood->cardId))['effectKey'] === 'competitiveness') {
                continue;
            }
            $highest = max($highest, $state->valueOf($mood->cardId));
        }

        return $highest + 1;
    }
}

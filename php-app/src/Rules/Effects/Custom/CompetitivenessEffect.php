<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Competitiveness: "While in play -- This mood's value is 1 more than the highest value among your
 * opponents' moods." With no opposing moods that's just 1. Two Competitiveness moods facing each other
 * would depend on one another's value for ever, so a mood that is already being computed further up the
 * stack counts as its printed value instead.
 */
final class CompetitivenessEffect extends AbstractMoodEffect
{
    /** @var array<int, true> card ids whose value is being computed right now */
    private static array $computing = [];

    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));
        if (isset(self::$computing[$cardId])) {
            return $row['baseValue'];
        }

        self::$computing[$cardId] = true;
        try {
            $ownerId = $state->ownerOf($cardId);
            $opponentIds = CustomEffectSupport::opponentIds($state, $ownerId);
            $highest = 0;
            foreach ($state->moodsInPlay() as $mood) {
                if (in_array($mood->ownerId, $opponentIds, true)) {
                    $highest = max($highest, $state->valueOf($mood->cardId));
                }
            }
        } finally {
            unset(self::$computing[$cardId]);
        }

        return $highest + 1;
    }
}

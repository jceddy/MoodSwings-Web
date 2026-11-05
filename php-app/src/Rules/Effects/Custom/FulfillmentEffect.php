<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ScoringBonusEffect;

/**
 * Fulfillment: "While in play -- While scoring, score each of your moods with a value of 6 or more an
 * extra time." Reads each mood's current value, so a conditional mood counts while it is showing 6. It
 * counts itself if it is ever worth 6 or more. Two Fulfillments each add a time.
 */
final class FulfillmentEffect extends AbstractMoodEffect implements ScoringBonusEffect
{
    private const MINIMUM_VALUE = 6;

    public function scoringBonus(BoardState $state, int $cardId, int $ownerId): int
    {
        $bonus = 0;
        foreach ($state->moodsOwnedBy($ownerId) as $mood) {
            $value = $state->valueOf($mood->cardId);
            if ($value >= self::MINIMUM_VALUE) {
                $bonus += $value;
            }
        }

        return $bonus;
    }
}

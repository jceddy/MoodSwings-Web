<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ScoringBonusEffect;

/**
 * Gratitude: "While in play -- While scoring, score each of your moods with a value of 2 or less an extra
 * time." Reads each mood's current value (the group considered the printed number and decided on the
 * current value), so a mood boosted past 2 stops getting the extra scoring and a suppressed one (value 0)
 * adds nothing.
 */
final class GratitudeEffect extends AbstractMoodEffect implements ScoringBonusEffect
{
    private const MAXIMUM_VALUE = 2;

    public function scoringBonus(BoardState $state, int $cardId, int $ownerId): int
    {
        $bonus = 0;
        foreach ($state->moodsOwnedBy($ownerId) as $mood) {
            $value = $state->valueOf($mood->cardId);
            if ($value <= self::MAXIMUM_VALUE) {
                $bonus += $value;
            }
        }

        return $bonus;
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Kindness: "After playing this mood, you may play an additional mood
 * this turn if it has a 1, 3, or 5 in its top right corner." Kindness's
 * own printed value (2) isn't in this set, which only makes sense because
 * "it" refers to whichever mood is chosen for the additional play, not
 * Kindness itself -- see BenevolenceEffect.
 */
final class KindnessEffect extends AbstractMoodEffect
{
    private const QUALIFYING_VALUES = [1, 3, 5];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(1, ['type' => 'base_value_in', 'values' => self::QUALIFYING_VALUES], sourceCardId: $cardId);
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Anger: "After playing this mood, you may put any number of moods with
 * total value 5 or less into the discard pile." Unlike Courage (a per-target
 * ceiling capped at two targets), any number of moods -- owned by anyone --
 * may be chosen as long as their combined value doesn't exceed the limit.
 */
final class AngerEffect extends AbstractMoodEffect
{
    private const MAX_TOTAL_VALUE = 5;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targets = array_unique($choices->ints('target_mood_ids'));
        if ($targets === []) {
            return;
        }

        $total = 0;
        foreach ($targets as $targetCardId) {
            if (!$state->isInPlay($targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not in play");
            }
            $total += $state->valueOf($targetCardId);
        }
        if ($total > self::MAX_TOTAL_VALUE) {
            throw new InvalidChoiceException('The total value of moods discarded by Anger cannot exceed 5');
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}

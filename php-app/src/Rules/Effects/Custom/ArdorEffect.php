<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ValueModifyingEffect;

/**
 * Ardor: "While in play -- Each of your red moods has its value increased by the number of red moods you
 * have." Ardor is red, so it counts itself and is increased by it too. Two Ardors each apply.
 */
final class ArdorEffect extends AbstractMoodEffect implements ValueModifyingEffect
{
    public function valueModifierFor(BoardState $state, int $sourceCardId, int $targetCardId): int
    {
        $ownerId = $state->ownerOf($sourceCardId);
        if ($state->ownerOf($targetCardId) !== $ownerId || $state->colorOf($targetCardId) !== 'red') {
            return 0;
        }

        $redMoods = 0;
        foreach ($state->moodsOwnedBy($ownerId) as $mood) {
            if ($state->colorOf($mood->cardId) === 'red') {
                $redMoods++;
            }
        }

        return $redMoods;
    }
}

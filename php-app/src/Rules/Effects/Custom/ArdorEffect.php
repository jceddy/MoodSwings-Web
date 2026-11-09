<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ValueModifyingEffect;

/**
 * Ardor: "While in play -- Each of your red moods has its value increased by the number of other red moods you
 * have." "Other" is relative to Ardor itself, so each Ardor counts the red moods its owner has besides itself; Ardor is
 * red, so it is still one of the moods that gets the increase. Two Ardors each apply (and each counts the other).
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
            if ($mood->cardId !== $sourceCardId && $state->colorOf($mood->cardId) === 'red') {
                $redMoods++;
            }
        }

        return $redMoods;
    }
}

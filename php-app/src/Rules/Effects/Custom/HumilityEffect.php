<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ValueModifyingEffect;

/**
 * Humility: "While in play -- Each of your moods with a 0, 1, or 2 in its top right corner has its value
 * increased by 1." The printed number counts, not the current value.
 */
final class HumilityEffect extends AbstractMoodEffect implements ValueModifyingEffect
{
    public function valueModifierFor(BoardState $state, int $sourceCardId, int $targetCardId): int
    {
        if ($state->ownerOf($targetCardId) !== $state->ownerOf($sourceCardId)) {
            return 0;
        }

        return CustomEffectSupport::printedNumber($state, $targetCardId) <= 2 ? 1 : 0;
    }
}

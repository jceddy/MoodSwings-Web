<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ValueModifyingEffect;

/**
 * Kinship: "While in play -- Each of your moods that shares a color with another of your moods has its
 * value increased by 1."
 */
final class KinshipEffect extends AbstractMoodEffect implements ValueModifyingEffect
{
    public function valueModifierFor(BoardState $state, int $sourceCardId, int $targetCardId): int
    {
        $ownerId = $state->ownerOf($sourceCardId);
        if ($state->ownerOf($targetCardId) !== $ownerId) {
            return 0;
        }

        $color = $state->colorOf($targetCardId);
        foreach ($state->moodsOwnedBy($ownerId) as $mood) {
            if ($mood->cardId !== $targetCardId && $state->colorOf($mood->cardId) === $color) {
                return 1;
            }
        }

        return 0;
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Vanity: "While in play, this mood's value increases by 1 for each of
 * your moods (including itself). If there are no cards in your hand,
 * this mood's value instead increases by 3 for each of your moods
 * (including itself)."
 */
final class VanityEffect extends AbstractMoodEffect
{
    private const VALUE_PER_MOOD = 1;
    private const VALUE_PER_MOOD_WITH_EMPTY_HAND = 3;

    public function computeValue(BoardState $state, int $cardId): int
    {
        $ownerId = $state->ownerOf($cardId);
        $moodCount = count($state->moodsOwnedBy($ownerId));
        $perMood = $state->hand($ownerId) === [] ? self::VALUE_PER_MOOD_WITH_EMPTY_HAND : self::VALUE_PER_MOOD;

        $row = $state->catalogRow($state->effectiveCardId($cardId));

        return $row['baseValue'] + $perMood * $moodCount;
    }
}

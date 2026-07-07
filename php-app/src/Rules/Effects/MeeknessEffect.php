<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Meekness: "After playing this mood, suppress all moods with a value of
 * 5 or more. Those moods remain suppressed for as long as you have this
 * mood." Mandatory (no "may"), and targets every qualifying mood
 * regardless of owner. Qualifying moods are snapshotted before any
 * suppression is applied, so suppressing one mood mid-resolution can't
 * change whether another still qualifies.
 */
final class MeeknessEffect extends AbstractMoodEffect
{
    private const MINIMUM_VALUE = 5;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $qualifying = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($state->valueOf($mood->cardId) >= self::MINIMUM_VALUE) {
                $qualifying[] = $mood->cardId;
            }
        }

        foreach ($qualifying as $targetCardId) {
            $state->suppress($targetCardId, 'while_source_in_play', $cardId);
        }
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\RoundWinEffect;

/**
 * Unity: "While in play -- After scoring, if you have five or more moods that share a color, you win the
 * round." Checked after the round is scored and before its winner is read (GameService::determineRoundWinner()),
 * like Sneakiness's swap. Only your own moods count, by current color; colorless moods share no color, so
 * Unity (colorless) never counts toward its own five. A suppressed mood still counts. If several players
 * qualify the ordinary scores and tiebreak pick among them.
 */
final class UnityEffect extends AbstractMoodEffect implements RoundWinEffect
{
    private const REQUIRED = 5;

    public function winsRoundAfterScoring(BoardState $state, int $cardId, int $ownerId): bool
    {
        $counts = [];
        foreach ($state->moodsOwnedBy($ownerId) as $mood) {
            $color = $state->colorOf($mood->cardId);
            if ($color !== 'colorless') {
                $counts[$color] = ($counts[$color] ?? 0) + 1;
            }
        }

        return $counts !== [] && max($counts) >= self::REQUIRED;
    }
}

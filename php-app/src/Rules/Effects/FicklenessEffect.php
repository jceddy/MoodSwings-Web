<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Fickleness: "After playing this mood, calculate the most common color
 * or colors among all moods. Put all moods other than this one that
 * share one of those colors into their players' hands." Same "most
 * common color(s)" computation as Bitterness, but returning moods to
 * hand instead of discarding them.
 */
final class FicklenessEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $counts = [];
        foreach ($state->moodsInPlay() as $mood) {
            $color = $state->colorOf($mood->cardId);
            $counts[$color] = ($counts[$color] ?? 0) + 1;
        }
        if ($counts === []) {
            return;
        }

        $maxCount = max($counts);
        $mostCommonColors = array_keys(array_filter($counts, static fn (int $count) => $count === $maxCount));

        $targets = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && in_array($state->colorOf($mood->cardId), $mostCommonColors, true)) {
                $targets[] = $mood->cardId;
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToHand($targetCardId);
        }
    }
}

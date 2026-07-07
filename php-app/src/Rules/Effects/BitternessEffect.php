<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Bitterness: "After playing this mood, calculate the most common color
 * or colors among all moods. Put all other moods that share one of those
 * colors into the discard pile." Ties for "most common" all qualify --
 * e.g. two colors tied at 3 moods each both count, not just one.
 */
final class BitternessEffect extends AbstractMoodEffect
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
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}

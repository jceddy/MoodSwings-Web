<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Denial: "After playing this mood, you may choose two other moods. If
 * the two chosen moods share a color or have the same value, put them
 * into their players' hands." Same qualifying condition as Rejection, but
 * returns the moods to hand instead of discarding them.
 */
final class DenialEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targets = $choices->ints('target_mood_ids');
        if ($targets === []) {
            return;
        }
        if (count($targets) !== 2 || $targets[0] === $targets[1]) {
            throw new InvalidChoiceException('Denial requires choosing exactly two different moods');
        }

        [$firstCardId, $secondCardId] = $targets;
        foreach ($targets as $targetCardId) {
            if ($targetCardId === $cardId || !$state->isInPlay($targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not a valid target for Denial");
            }
        }
        if (
            $state->colorOf($firstCardId) !== $state->colorOf($secondCardId)
            && $state->valueOf($firstCardId) !== $state->valueOf($secondCardId)
        ) {
            throw new InvalidChoiceException('The two chosen moods must share a color or have the same value');
        }

        $state->moveInPlayToHand($firstCardId);
        $state->moveInPlayToHand($secondCardId);
    }
}

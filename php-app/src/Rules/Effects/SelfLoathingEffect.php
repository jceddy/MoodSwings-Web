<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Self-Loathing: "To play this card, put one or more of your moods into
 * the discard pile. If you can't do that, you can't play this card." A
 * to-play-only cost with no while-in-play or after-playing ability --
 * its value is just its flat base value once the cost is paid.
 */
final class SelfLoathingEffect extends AbstractMoodEffect
{
    public function canPayToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): bool
    {
        return $state->moodsOwnedBy($playerId) !== [];
    }

    public function payToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targets = array_unique($choices->ints('discard_mood_ids'));
        if ($targets === []) {
            throw new InvalidChoiceException('Self-Loathing requires discarding at least one of your moods');
        }

        foreach ($targets as $targetCardId) {
            if (!$state->isInPlay($targetCardId) || $state->ownerOf($targetCardId) !== $playerId) {
                throw new InvalidChoiceException("Card {$targetCardId} is not one of player {$playerId}'s moods in play");
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}

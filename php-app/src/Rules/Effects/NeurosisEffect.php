<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Neurosis: "To play this card, put one or more of your moods into your
 * hand. If you can't do that, you can't play this card." The same shape
 * as Self-Loathing's mandatory to-play cost, but returning moods to hand
 * instead of discarding them.
 */
final class NeurosisEffect extends AbstractMoodEffect
{
    public function canPayToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): bool
    {
        return $state->moodsOwnedBy($playerId) !== [];
    }

    public function payToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targets = array_unique($choices->ints('hand_mood_ids'));
        if ($targets === []) {
            throw new InvalidChoiceException('Neurosis requires returning at least one of your moods to hand');
        }

        foreach ($targets as $targetCardId) {
            if (!$state->isInPlay($targetCardId) || $state->ownerOf($targetCardId) !== $playerId) {
                throw new InvalidChoiceException("Card {$targetCardId} is not one of player {$playerId}'s moods in play");
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToHand($targetCardId);
        }
    }
}

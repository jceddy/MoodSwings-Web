<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Shock: "After playing this mood, choose up to two players. For each
 * chosen player, put one of their moods with a value of 3 or less into
 * the discard pile."
 */
final class ShockEffect extends AbstractMoodEffect
{
    private const MAX_TARGETS = 2;
    private const MAXIMUM_VALUE = 3;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targets = $choices->ints('target_mood_ids');
        if (count($targets) > self::MAX_TARGETS) {
            throw new InvalidChoiceException('Shock can only affect up to two moods');
        }

        $affectedOwners = [];
        foreach ($targets as $targetCardId) {
            if (!$state->isInPlay($targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not in play");
            }

            $owner = $state->ownerOf($targetCardId);
            if (isset($affectedOwners[$owner])) {
                throw new InvalidChoiceException('Shock can only affect one mood per chosen player');
            }
            if ($state->valueOf($targetCardId) > self::MAXIMUM_VALUE) {
                throw new InvalidChoiceException("Card {$targetCardId} does not have a value of 3 or less");
            }

            $affectedOwners[$owner] = true;
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}

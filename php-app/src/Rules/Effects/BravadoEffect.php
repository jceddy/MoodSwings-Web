<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Bravado: "After playing this mood, you may put one of your other moods
 * into the discard pile. If you do, you may play an additional mood this
 * turn." The bonus play is unconditional (unlike Benevolence/Friendliness/
 * Kindness/Eagerness) -- the cost is what's optional here, not a
 * restriction on which card can use the grant.
 */
final class BravadoEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardMoodId = $choices->int('discard_mood_id');
        if ($discardMoodId === null) {
            return;
        }
        if ($discardMoodId === $cardId || !$state->isInPlay($discardMoodId) || $state->ownerOf($discardMoodId) !== $playerId) {
            throw new InvalidChoiceException("Card {$discardMoodId} is not one of player {$playerId}'s other moods in play");
        }

        $state->moveInPlayToDiscard($discardMoodId);
        $state->grantExtraPlay(sourceCardId: $cardId);
    }
}

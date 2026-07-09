<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Fear: "After playing this mood, you may put another one of your moods
 * into your hand. You may play an additional mood this turn." Unlike
 * Ambition/Bravado/Thrill, there's no "if you do" linking the two halves
 * -- the extra play is unconditional (like Charity's), and returning a
 * mood to hand is a separate, independent option.
 */
final class FearEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $handMoodId = $choices->int('hand_mood_id');
        if ($handMoodId !== null) {
            if ($handMoodId === $cardId || !$state->isInPlay($handMoodId) || $state->ownerOf($handMoodId) !== $playerId) {
                throw new InvalidChoiceException("Card {$handMoodId} is not one of player {$playerId}'s other moods in play");
            }
            $state->moveInPlayToHand($handMoodId);
        }

        $state->grantExtraPlay(sourceCardId: $cardId);
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Rashness: "After playing this mood -- You may put one of your opponents' moods with a value of
 * 1 or less into the discard pile. You may play an additional mood this turn." The two halves are
 * independent: the extra play is unconditional.
 */
final class RashnessEffect extends AbstractMoodEffect
{
    private const MAXIMUM_VALUE = 1;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardId = $choices->int('target_mood_id');
        if ($targetCardId !== null) {
            if (!$state->isInPlay($targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not in play");
            }
            if (!in_array($state->ownerOf($targetCardId), CustomEffectSupport::opponentIds($state, $playerId), true)) {
                throw new InvalidChoiceException("Rashness can only target one of your opponents' moods");
            }
            if ($state->valueOf($targetCardId) > self::MAXIMUM_VALUE) {
                throw new InvalidChoiceException('Rashness can only target a mood with a value of 1 or less');
            }

            $state->moveInPlayToDiscard($targetCardId);
        }

        $state->grantExtraPlay(sourceCardId: $cardId);
    }
}

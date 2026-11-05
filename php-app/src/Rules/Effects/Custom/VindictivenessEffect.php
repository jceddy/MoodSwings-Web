<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Vindictiveness: "After playing this mood -- You may put one of your other moods into the discard pile. If you
 * do, you may put a mood with a lower value than that mood's into the discard pile." Both values are read on the
 * board as it stands before either mood leaves ("compared as it was in play"); the second step is optional, and
 * the mood it takes may be anyone's -- this one included, since it is a mood in play with a lower value than the
 * sacrifice. A target named without a sacrifice is refused.
 */
final class VindictivenessEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $sacrificeId = $choices->int('discard_mood_id');
        $targetId = $choices->int('target_mood_id');
        if ($sacrificeId === null) {
            if ($targetId !== null) {
                throw new InvalidChoiceException('Vindictiveness only removes a lower value mood if you put one of your other moods into the discard pile');
            }

            return;
        }

        if ($sacrificeId === $cardId || !$state->isInPlay($sacrificeId) || $state->ownerOf($sacrificeId) !== $playerId) {
            throw new InvalidChoiceException("Card {$sacrificeId} is not one of player {$playerId}'s other moods in play");
        }

        if ($targetId !== null) {
            if (!$state->isInPlay($targetId) || $targetId === $sacrificeId) {
                throw new InvalidChoiceException("Card {$targetId} is not another mood in play");
            }
            if ($state->valueOf($targetId) >= $state->valueOf($sacrificeId)) {
                throw new InvalidChoiceException("Card {$targetId} does not have a lower value than the mood put into the discard pile");
            }
        }

        $state->moveInPlayToDiscard($sacrificeId);
        if ($targetId !== null) {
            $state->moveInPlayToDiscard($targetId);
        }
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Pacifism: "After playing this mood, choose up to two players. For each
 * chosen player, suppress one of their moods. It remains suppressed for
 * as long as you have this mood." Structured like Courage (one target per
 * chosen player), but with no value floor and suppressing rather than
 * discarding.
 */
final class PacifismEffect extends AbstractMoodEffect
{
    private const MAX_TARGETS = 2;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targets = $choices->ints('target_mood_ids');
        if (count($targets) > self::MAX_TARGETS) {
            throw new InvalidChoiceException('Pacifism can only affect up to two moods');
        }

        $affectedOwners = [];
        foreach ($targets as $targetCardId) {
            if (!$state->isInPlay($targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not in play");
            }

            $owner = $state->ownerOf($targetCardId);
            if (isset($affectedOwners[$owner])) {
                throw new InvalidChoiceException('Pacifism can only affect one mood per chosen player');
            }
            $affectedOwners[$owner] = true;
        }

        foreach ($targets as $targetCardId) {
            $state->suppress($targetCardId, 'while_source_in_play', $cardId);
        }
    }
}

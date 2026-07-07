<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Panic: "After playing this mood, choose up to two players. For each
 * chosen player, put one of their moods into their hand. You can't put
 * this mood into your hand this way." Structured like Pacifism/Anxiety/
 * Spite/Shock, but unconditional aside from excluding Panic itself.
 */
final class PanicEffect extends AbstractMoodEffect
{
    private const MAX_TARGETS = 2;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targets = $choices->ints('target_mood_ids');
        if (count($targets) > self::MAX_TARGETS) {
            throw new InvalidChoiceException('Panic can only affect up to two moods');
        }

        $affectedOwners = [];
        foreach ($targets as $targetCardId) {
            if ($targetCardId === $cardId) {
                throw new InvalidChoiceException('Panic cannot target itself');
            }
            if (!$state->isInPlay($targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not in play");
            }

            $owner = $state->ownerOf($targetCardId);
            if (isset($affectedOwners[$owner])) {
                throw new InvalidChoiceException('Panic can only affect one mood per chosen player');
            }
            $affectedOwners[$owner] = true;
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToHand($targetCardId);
        }
    }
}

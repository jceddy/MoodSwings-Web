<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Courage: "After playing this mood, choose up to two players. For each
 * chosen player, put one of their moods with a value of 5 or more into
 * the discard pile." The choice is modeled as the concrete target moods
 * (one per chosen player) rather than "players" plus a separate "which of
 * their moods" step, since that's exactly the information needed to
 * resolve the effect.
 *
 * The "5 or more" check reads BoardState::valueOf() live, which is what
 * makes the Extended Rules' Courage/Obsession example work correctly for
 * free: by the time this runs, "while in play" effects have already been
 * applied, so a mood that only reaches 5+ because of another card already
 * in play is a legal target.
 */
final class CourageEffect extends AbstractMoodEffect
{
    private const MAX_TARGETS = 2;
    private const MINIMUM_VALUE = 5;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targets = $choices->ints('target_mood_ids');
        if (count($targets) > self::MAX_TARGETS) {
            throw new InvalidChoiceException('Courage can only affect up to two moods');
        }

        $affectedOwners = [];
        foreach ($targets as $targetCardId) {
            if (!$state->isInPlay($targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not in play");
            }

            $owner = $state->ownerOf($targetCardId);
            if (isset($affectedOwners[$owner])) {
                throw new InvalidChoiceException('Courage can only affect one mood per chosen player');
            }
            if ($state->valueOf($targetCardId) < self::MINIMUM_VALUE) {
                throw new InvalidChoiceException("Card {$targetCardId} does not have a value of 5 or more");
            }

            $affectedOwners[$owner] = true;
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}

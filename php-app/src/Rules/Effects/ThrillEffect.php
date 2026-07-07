<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Thrill: "After playing this mood, you may put any number of your
 * other moods into your hand. If you do, you may play that many
 * additional moods this turn." Each returned mood grants exactly one
 * unconditional extra play.
 */
final class ThrillEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targets = array_unique($choices->ints('hand_mood_ids'));
        if ($targets === []) {
            return;
        }

        foreach ($targets as $targetCardId) {
            if ($targetCardId === $cardId || !$state->isInPlay($targetCardId) || $state->ownerOf($targetCardId) !== $playerId) {
                throw new InvalidChoiceException("Card {$targetCardId} is not one of player {$playerId}'s other moods in play");
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToHand($targetCardId);
        }
        $state->grantExtraPlay(count($targets));
    }
}

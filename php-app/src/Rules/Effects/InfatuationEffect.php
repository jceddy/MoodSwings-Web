<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Infatuation: "After playing this mood, you may put two of your other
 * moods into the discard pile. If you do, this mood's value becomes 9."
 * A one-time value override paid for with two of the acting player's own
 * moods, rather than a hand or discard-pile card.
 */
final class InfatuationEffect extends AbstractMoodEffect
{
    private const COST_COUNT = 2;
    private const BOOSTED_VALUE = 9;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targets = array_unique($choices->ints('discard_mood_ids'));
        if ($targets === []) {
            return;
        }
        if (count($targets) !== self::COST_COUNT) {
            throw new InvalidChoiceException('Infatuation requires discarding exactly two of your other moods');
        }

        foreach ($targets as $targetCardId) {
            if ($targetCardId === $cardId || !$state->isInPlay($targetCardId) || $state->ownerOf($targetCardId) !== $playerId) {
                throw new InvalidChoiceException("Card {$targetCardId} is not one of player {$playerId}'s other moods in play");
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
        $state->setValueOverride($cardId, self::BOOSTED_VALUE);
    }
}

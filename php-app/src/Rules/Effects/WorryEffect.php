<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Worry: "After playing this mood, you may put one of your white or
 * black moods into your hand. If you do, put up to two moods other than
 * this one, each with a value of 3 or less, into their players' hands." A
 * two-stage optional effect -- the second stage only happens if the first
 * was taken.
 */
final class WorryEffect extends AbstractMoodEffect
{
    private const QUALIFYING_COLORS = ['white', 'black'];
    private const MAX_SECONDARY_TARGETS = 2;
    private const MAXIMUM_VALUE = 3;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $ownMoodId = $choices->int('hand_mood_id');
        if ($ownMoodId === null) {
            return;
        }
        if (!$state->isInPlay($ownMoodId) || $state->ownerOf($ownMoodId) !== $playerId) {
            throw new InvalidChoiceException("Card {$ownMoodId} is not one of player {$playerId}'s moods in play");
        }
        if (!in_array($state->colorOf($ownMoodId), self::QUALIFYING_COLORS, true)) {
            throw new InvalidChoiceException('Worry requires returning a white or black mood');
        }

        $state->moveInPlayToHand($ownMoodId);

        $targets = array_unique($choices->ints('target_mood_ids'));
        if (count($targets) > self::MAX_SECONDARY_TARGETS) {
            throw new InvalidChoiceException('Worry can only affect up to two additional moods');
        }

        foreach ($targets as $targetCardId) {
            if ($targetCardId === $cardId || $targetCardId === $ownMoodId) {
                throw new InvalidChoiceException("Card {$targetCardId} is not a valid target for Worry");
            }
            if (!$state->isInPlay($targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not in play");
            }
            if ($state->valueOf($targetCardId) > self::MAXIMUM_VALUE) {
                throw new InvalidChoiceException("Card {$targetCardId} does not have a value of 3 or less");
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToHand($targetCardId);
        }
    }
}

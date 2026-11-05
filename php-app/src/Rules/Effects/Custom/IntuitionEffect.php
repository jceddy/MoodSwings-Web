<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Intuition: "After playing this mood -- Choose a number. Put all other moods with the chosen
 * number in their top right corners into the discard pile." The number is a printed number (a
 * mood showing a different value right now still counts as the number it prints). Mandatory.
 */
final class IntuitionEffect extends AbstractMoodEffect
{
    private const HIGHEST_PRINTED_NUMBER = 12;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $number = $choices->requireInt('value');
        if ($number < 0 || $number > self::HIGHEST_PRINTED_NUMBER) {
            throw new InvalidChoiceException("{$number} is not a number any mood prints");
        }

        $targets = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && CustomEffectSupport::printedNumber($state, $mood->cardId) === $number) {
                $targets[] = $mood->cardId;
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}

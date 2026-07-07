<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Disorientation: "After playing this mood, you may choose a number. If
 * you do, put all other moods with the chosen value into their players'
 * hands." A third variant of the "choose a number, mass-affect matching
 * moods" family alongside Repentance (suppress, optional) and Rebellion
 * (discard, mandatory).
 */
final class DisorientationEffect extends AbstractMoodEffect
{
    private const MIN_VALUE = 0;
    private const MAX_VALUE = 12;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $value = $choices->int('value');
        if ($value === null) {
            return;
        }
        if ($value < self::MIN_VALUE || $value > self::MAX_VALUE) {
            throw new InvalidChoiceException('Disorientation requires choosing a value between 0 and 12');
        }

        $targets = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $state->valueOf($mood->cardId) === $value) {
                $targets[] = $mood->cardId;
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToHand($targetCardId);
        }
    }
}

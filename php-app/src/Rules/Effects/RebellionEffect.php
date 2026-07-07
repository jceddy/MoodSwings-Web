<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Rebellion: "After playing this mood, choose 0, 1, 2, or 3. Put all
 * other moods with the chosen value into the discard pile." Unlike
 * Repentance's "you may", this choice is mandatory.
 */
final class RebellionEffect extends AbstractMoodEffect
{
    private const VALID_VALUES = [0, 1, 2, 3];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $value = $choices->requireInt('value');
        if (!in_array($value, self::VALID_VALUES, true)) {
            throw new InvalidChoiceException('Rebellion requires choosing 0, 1, 2, or 3');
        }

        $targets = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $state->valueOf($mood->cardId) === $value) {
                $targets[] = $mood->cardId;
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}

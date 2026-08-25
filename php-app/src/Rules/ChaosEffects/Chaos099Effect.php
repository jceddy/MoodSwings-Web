<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_099 (uncommon, after_playing): "Choose 0, 1, 2, or 3. Put all other moods with the chosen value into the discard pile." */
final class Chaos099Effect extends AbstractChaosMoodEffect
{
    private const VALID_VALUES = [0, 1, 2, 3];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $chosenValue = $choices->requireInt('value');
        if (!in_array($chosenValue, self::VALID_VALUES, true)) {
            throw new InvalidChoiceException("{$chosenValue} is not a valid choice");
        }

        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $state->valueOf($mood->cardId) === $chosenValue) {
                $state->moveInPlayToDiscard($mood->cardId);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/** chaos_035 (rare, after_playing): "You may choose a number. If you do, put all other moods with the chosen value into their players' hands." */
final class Chaos035Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $chosenValue = $choices->int('value');
        if ($chosenValue === null) {
            return;
        }

        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $state->valueOf($mood->cardId) === $chosenValue) {
                $state->moveInPlayToHand($mood->cardId);
            }
        }
    }
}

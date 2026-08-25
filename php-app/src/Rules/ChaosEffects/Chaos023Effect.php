<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/** chaos_023 (uncommon, after_playing): "You may choose a number. If you do, suppress all other moods with the chosen value. They remain suppressed until the end of this round." */
final class Chaos023Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $chosenValue = $choices->int('value');
        if ($chosenValue === null) {
            return;
        }

        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $state->valueOf($mood->cardId) === $chosenValue) {
                $state->suppress($mood->cardId, 'end_of_round', $cardId);
            }
        }
    }
}

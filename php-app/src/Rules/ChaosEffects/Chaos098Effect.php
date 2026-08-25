<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/** chaos_098 (uncommon, after_playing): "You may put all other moods with a value of 2 or less into the discard pile." */
final class Chaos098Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        if (!$choices->bool('confirm')) {
            return;
        }

        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $state->valueOf($mood->cardId) <= 2) {
                $state->moveInPlayToDiscard($mood->cardId);
            }
        }
    }
}

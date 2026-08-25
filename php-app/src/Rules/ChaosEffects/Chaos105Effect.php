<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/** chaos_105 (rare, after_playing): "You may put all other moods into the discard pile." */
final class Chaos105Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        if (!$choices->bool('confirm')) {
            return;
        }

        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId) {
                $state->moveInPlayToDiscard($mood->cardId);
            }
        }
    }
}

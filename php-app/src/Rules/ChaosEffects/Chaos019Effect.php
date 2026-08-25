<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/** chaos_019 (rare, after_playing): "Suppress all moods with a value of 5 or more. Those moods remain suppressed for as long as you have this mood." */
final class Chaos019Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        foreach ($state->moodsInPlay() as $mood) {
            if ($state->valueOf($mood->cardId) >= 5) {
                $state->suppress($mood->cardId, 'while_source_in_play', $cardId);
            }
        }
    }
}

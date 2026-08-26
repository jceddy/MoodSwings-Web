<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/** chaos_108 (mythic, after_playing): "Permanently increase the value of each of your moods that shares a color with this mood by that mood's value." Each qualifying mood's own current value doubles -- "by that mood's value" adds its own current value to itself. */
final class Chaos108Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $color = $state->colorOf($cardId);
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            if ($mood->cardId !== $cardId && $state->colorOf($mood->cardId) === $color) {
                $current = $state->valueOf($mood->cardId);
                $state->setChaosValueOverride($mood->cardId, $current + $current);
            }
        }
    }
}

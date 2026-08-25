<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/** chaos_057 (uncommon, after_playing): "Calculate the most common color or colors among all moods. Put all other moods that share one of those colors into the discard pile." */
final class Chaos057Effect extends AbstractChaosMoodEffect
{
    private const COLORS = ['white', 'blue', 'black', 'red', 'green'];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $counts = array_fill_keys(self::COLORS, 0);
        foreach ($state->moodsInPlay() as $mood) {
            $counts[$state->colorOf($mood->cardId)]++;
        }
        $highest = max($counts);
        if ($highest === 0) {
            return;
        }
        $mostCommonColors = array_keys(array_filter($counts, static fn (int $count) => $count === $highest));

        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && in_array($state->colorOf($mood->cardId), $mostCommonColors, true)) {
                $state->moveInPlayToDiscard($mood->cardId);
            }
        }
    }
}

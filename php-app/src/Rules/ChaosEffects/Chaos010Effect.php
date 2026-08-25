<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_010 (rare, after_playing): "Starting with the next player in turn
 * order, each player may choose a color. Put each other mood that shares
 * one of those colors into the discard pile." Chaos Draft effects never
 * pause for another player's own decision (see ChaosMoodEffect's own
 * docblock) -- unlike Effects/DisillusionmentEffect.php's identical-
 * shaped printed ability, which genuinely asks each player, this
 * resolves in one synchronous step by having every player in the queue
 * contribute one uniformly-random color instead of a real pick.
 */
final class Chaos010Effect extends AbstractChaosMoodEffect
{
    private const COLORS = ['white', 'blue', 'black', 'red', 'green'];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $order = $state->activePlayerOrder();
        $index = array_search($playerId, $order, true);
        $queue = array_merge(array_slice($order, $index + 1), array_slice($order, 0, $index + 1));

        $chosenColors = [];
        foreach ($queue as $ignored) {
            $chosenColors[] = self::COLORS[array_rand(self::COLORS)];
        }
        $chosenColors = array_unique($chosenColors);

        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId === $cardId) {
                continue;
            }
            if (in_array($state->colorOf($mood->cardId), $chosenColors, true)) {
                $state->moveInPlayToDiscard($mood->cardId);
            }
        }
    }
}

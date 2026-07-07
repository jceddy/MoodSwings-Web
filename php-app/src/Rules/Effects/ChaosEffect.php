<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Chaos: "After playing this mood, shuffle all moods together. Starting
 * with you and going in turn order, deal those moods out one at a time
 * to each player. Moods may change players, but their after-playing
 * effects don't happen again." A genuine shuffle -- not a substitute for
 * an unavailable player choice -- so `shuffle()` is simply what the card
 * says to do. Reassigns ownership only (via `giveInPlayToPlayer()`);
 * nothing else about a mood (suppression, effectState, etc.) changes,
 * and "after-playing effects don't happen again" needs no special
 * handling since nothing here calls afterPlaying() in the first place.
 * Chaos itself is one of "all moods" too, so it can end up reassigned
 * along with everything else.
 */
final class ChaosEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $moodCardIds = array_keys($state->moodsInPlay());
        shuffle($moodCardIds);

        $order = $state->playerOrder();
        $startIndex = array_search($playerId, $order, true);
        $dealOrder = array_merge(array_slice($order, $startIndex), array_slice($order, 0, $startIndex));

        foreach ($moodCardIds as $index => $dealtCardId) {
            $state->giveInPlayToPlayer($dealtCardId, $dealOrder[$index % count($dealOrder)]);
        }
    }
}

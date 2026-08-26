<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_085 (mythic, after_playing): "Shuffle all moods together.
 * Starting with you and going in turn order, deal those moods out one at
 * a time to each player. (Moods may change players but 'After playing
 * this mood' effects won't happen again.)" BoardState::giveInPlayToPlayer()
 * only ever reassigns ownership -- it never re-invokes afterPlaying() --
 * so the parenthetical is already true for free.
 */
final class Chaos085Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $moodCardIds = array_keys($state->moodsInPlay());
        shuffle($moodCardIds);

        $order = $state->activePlayerOrder();
        $index = array_search($playerId, $order, true);
        $dealOrder = array_merge(array_slice($order, $index), array_slice($order, 0, $index));

        $recipientIndex = 0;
        foreach ($moodCardIds as $movingCardId) {
            $recipientId = $dealOrder[$recipientIndex % count($dealOrder)];
            $recipientIndex++;
            if ($state->ownerOf($movingCardId) !== $recipientId) {
                $state->giveInPlayToPlayer($movingCardId, $recipientId);
            }
        }
    }
}

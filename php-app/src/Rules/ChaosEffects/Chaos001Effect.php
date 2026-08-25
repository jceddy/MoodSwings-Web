<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_001 (rare, after_playing): "If the discard pile has at least one
 * card in it, this mood's value becomes 7. Then starting with the next
 * player in turn order, each player takes a random card from the discard
 * pile and puts it into their hand. Put the rest of the discard pile onto
 * the bottom of the deck in a random order."
 */
final class Chaos001Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        if ($state->discardPile() === []) {
            return;
        }

        $state->setValueOverride($cardId, 7);

        $order = $state->activePlayerOrder();
        $index = array_search($playerId, $order, true);
        $queue = array_merge(array_slice($order, $index + 1), array_slice($order, 0, $index + 1));

        foreach ($queue as $recipientId) {
            $pile = $state->discardPile();
            if ($pile === []) {
                break;
            }
            $takenCardId = $pile[array_rand($pile)];
            $state->moveDiscardToHand($recipientId, $takenCardId);
        }

        $remaining = $state->discardPile();
        shuffle($remaining);
        foreach ($remaining as $remainingCardId) {
            $state->moveDiscardToBottomOfDeck($remainingCardId);
        }
    }
}

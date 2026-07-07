<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Altruism: "After playing this mood, if the discard pile has at least
 * one card in it, this mood's value becomes 7. Then, starting with the
 * next player in turn order, each player takes a random card from the
 * discard pile and puts it into their hand. Put the rest of the discard
 * pile onto the bottom of the deck in a random order." One card per
 * player, not repeated cycling -- "the rest" only makes sense if the
 * distribution is a single pass through turn order.
 */
final class AltruismEffect extends AbstractMoodEffect
{
    private const BOOSTED_VALUE = 7;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        if ($state->discardPile() === []) {
            return;
        }
        $state->setValueOverride($cardId, self::BOOSTED_VALUE);

        $order = $state->playerOrder();
        $startIndex = array_search($playerId, $order, true);
        $turnOrder = array_merge(array_slice($order, $startIndex + 1), array_slice($order, 0, $startIndex + 1));

        foreach ($turnOrder as $recipientId) {
            $discard = $state->discardPile();
            if ($discard === []) {
                break;
            }
            $randomCardId = $discard[array_rand($discard)];
            $state->moveDiscardToHand($recipientId, $randomCardId);
        }

        $remaining = $state->discardPile();
        shuffle($remaining);
        foreach ($remaining as $remainingCardId) {
            $state->moveDiscardToBottomOfDeck($remainingCardId);
        }
    }
}

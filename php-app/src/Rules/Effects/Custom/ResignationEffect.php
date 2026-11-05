<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Resignation: "To play this card -- Put a card from your hand on the bottom of the deck. (If you can't do that,
 * you can't play this card.)" A to-play-only cost with no other ability -- the card being played can't pay for
 * itself, so it needs another card in hand.
 */
final class ResignationEffect extends AbstractMoodEffect
{
    public function canPayToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): bool
    {
        return array_diff($state->hand($playerId), [$cardId]) !== [];
    }

    public function payToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $handCardId = $choices->requireInt('hand_card_id');
        if ($handCardId === $cardId) {
            throw new InvalidChoiceException('Resignation cannot pay its own cost by putting itself on the bottom of the deck');
        }
        if (!$state->isInHand($playerId, $handCardId)) {
            throw new InvalidChoiceException("Card {$handCardId} is not in player {$playerId}'s hand");
        }

        $state->moveHandToBottomOfDeck($playerId, $handCardId);
    }
}

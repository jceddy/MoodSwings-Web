<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** Zeal: "After playing this mood, you may put a card from your hand on the bottom of the deck. If you do, draw a card." */
final class ZealEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $handCardId = $choices->int('hand_card_id');
        if ($handCardId === null) {
            return;
        }
        if (!$state->isInHand($playerId, $handCardId)) {
            throw new InvalidChoiceException("Card {$handCardId} is not in player {$playerId}'s hand");
        }

        $state->moveHandToBottomOfDeck($playerId, $handCardId);
        $state->drawCard($playerId);
    }
}

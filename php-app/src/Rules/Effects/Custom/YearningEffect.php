<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Yearning: "After playing this mood -- If there are two or more blue and/or red moods, you may
 * discard a card from your hand. If you do, put a card from the discard pile into your hand."
 * Discarding is the "you may"; the pick from the pile then comes with it (a discard with no pile
 * card named is allowed -- it just gets nothing back).
 */
final class YearningEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $handCardId = $choices->int('hand_card_id');
        if ($handCardId === null) {
            return;
        }
        if (CustomEffectSupport::countMoodsOfColors($state, ['blue', 'red']) < 2) {
            throw new InvalidChoiceException("There aren't two or more blue and/or red moods");
        }
        if (!$state->isInHand($playerId, $handCardId)) {
            throw new InvalidChoiceException("Card {$handCardId} is not in player {$playerId}'s hand");
        }

        $discardCardId = $choices->int('discard_card_id');
        if ($discardCardId !== null && $discardCardId !== $handCardId && !in_array($discardCardId, $state->discardPile(), true)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not in the discard pile");
        }

        $state->moveHandToDiscard($playerId, $handCardId);

        if ($discardCardId !== null) {
            $state->moveDiscardToHand($playerId, $discardCardId);
        }
    }
}

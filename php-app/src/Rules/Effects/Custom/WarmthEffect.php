<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** Warmth: "After playing this mood -- If there are two or more red and/or white moods, you may put a card from the discard pile into your hand." */
final class WarmthEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardCardId = $choices->int('discard_card_id');
        if ($discardCardId === null) {
            return;
        }
        if (CustomEffectSupport::countMoodsOfColors($state, ['red', 'white']) < 2) {
            throw new InvalidChoiceException("There aren't two or more red and/or white moods");
        }
        if (!in_array($discardCardId, $state->discardPile(), true)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not in the discard pile");
        }

        $state->moveDiscardToHand($playerId, $discardCardId);
    }
}

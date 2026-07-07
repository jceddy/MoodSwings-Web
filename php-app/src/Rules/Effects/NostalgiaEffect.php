<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Nostalgia: "After playing this mood, you may put a card from the
 * discard pile into your hand. You may play an additional mood this
 * turn." Like Fear, no "if you do" links the two halves -- the extra
 * play is unconditional, and returning a discard-pile card to hand is a
 * separate, independent option.
 */
final class NostalgiaEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardCardId = $choices->int('discard_card_id');
        if ($discardCardId !== null) {
            if (!in_array($discardCardId, $state->discardPile(), true)) {
                throw new InvalidChoiceException("Card {$discardCardId} is not in the discard pile");
            }
            $state->moveDiscardToHand($playerId, $discardCardId);
        }

        $state->grantExtraPlay();
    }
}

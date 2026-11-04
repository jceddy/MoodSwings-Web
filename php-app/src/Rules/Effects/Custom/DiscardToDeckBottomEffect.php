<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Reminiscence: "After playing this mood -- You may put a card from the discard pile on the
 * bottom of the deck. If you do, draw a card." Remorse is the same plus "You may play an
 * additional mood this turn" (unconditional, like Nostalgia's -- no "if you do" ties it to the
 * first half).
 */
final class DiscardToDeckBottomEffect extends AbstractMoodEffect
{
    public function __construct(private readonly bool $grantsExtraPlay = false)
    {
    }

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardCardId = $choices->int('discard_card_id');
        if ($discardCardId !== null) {
            if (!in_array($discardCardId, $state->discardPile(), true)) {
                throw new InvalidChoiceException("Card {$discardCardId} is not in the discard pile");
            }
            $state->moveDiscardToBottomOfDeck($discardCardId);
            $state->drawCard($playerId);
        }

        if ($this->grantsExtraPlay) {
            $state->grantExtraPlay(sourceCardId: $cardId);
        }
    }
}

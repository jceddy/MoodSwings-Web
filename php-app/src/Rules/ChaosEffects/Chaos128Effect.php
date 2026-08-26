<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_128 (common, after_playing): "You may put a card from the
 * discard pile into your hand. You may play an additional mood this
 * turn." Two independent clauses -- the extra play is granted regardless
 * of whether the optional discard-pile pickup happens (see
 * Chaos038Effect's own identically-shaped docblock).
 */
final class Chaos128Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardCardId = $choices->int('discard_card_id');
        if ($discardCardId !== null) {
            if (!$state->isInDiscardPile($discardCardId)) {
                throw new InvalidChoiceException("Card {$discardCardId} is not in the discard pile");
            }
            $state->moveDiscardToHand($playerId, $discardCardId);
        }

        $state->grantExtraPlay(1, sourceCardId: $cardId);
    }
}

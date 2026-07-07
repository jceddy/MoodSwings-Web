<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Ambition: "After playing this mood, you may discard a card from your
 * hand. If you do, you may play an additional mood this turn." The
 * unconditional bonus play is gated behind a hand-card cost -- compare
 * Bravado, which pays the same kind of cost from a mood already in play
 * instead.
 */
final class AmbitionEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardCardId = $choices->int('discard_card_id');
        if ($discardCardId === null) {
            return;
        }
        if (!$state->isInHand($playerId, $discardCardId)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not in player {$playerId}'s hand");
        }

        $state->moveHandToDiscard($playerId, $discardCardId);
        $state->grantExtraPlay();
    }
}

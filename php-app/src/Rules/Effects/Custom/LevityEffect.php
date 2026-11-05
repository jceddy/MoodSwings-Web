<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Levity: "After playing this mood -- You may put this mood on the bottom of the deck. If you do,
 * draw a card. You may play an additional mood this turn." The extra play is unconditional.
 */
final class LevityEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        if ($choices->bool('bottom_deck_self') && $state->isInPlay($cardId)) {
            $state->moveInPlayToBottomOfDeck($cardId);
            $state->drawCard($playerId);
        }

        $state->grantExtraPlay(sourceCardId: $cardId);
    }
}

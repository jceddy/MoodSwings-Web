<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Hate: "After playing this mood, you may put any mood on the bottom of
 * the deck. If you do, draw a card." Unlike Conviction (whose target's
 * *owner* draws), the acting player always draws here, regardless of
 * whose mood was bottomed.
 */
final class HateEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardId = $choices->int('target_mood_id');
        if ($targetCardId === null) {
            return;
        }
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }

        $state->moveInPlayToBottomOfDeck($targetCardId);
        $state->drawCard($playerId);
    }
}

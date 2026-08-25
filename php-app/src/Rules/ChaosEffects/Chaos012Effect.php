<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_012 (uncommon, after_playing): "You may discard a green or blue card from your hand. If you do, suppress any mood. It remains suppressed for as long as you have this mood." */
final class Chaos012Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardCardId = $choices->int('discard_card_id');
        if ($discardCardId === null) {
            return;
        }

        if (!$state->isInHand($playerId, $discardCardId)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not in your hand");
        }
        $color = $state->colorOf($discardCardId);
        if (!in_array($color, ['green', 'blue'], true)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not green or blue");
        }

        $targetCardId = $choices->requireInt('suppress_mood_card_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }

        $state->moveHandToDiscard($playerId, $discardCardId);
        $state->suppress($targetCardId, 'while_source_in_play', $cardId);
    }
}

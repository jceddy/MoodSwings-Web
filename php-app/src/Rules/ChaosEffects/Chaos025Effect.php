<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_025 (rare, after_playing): "You may discard a card from your hand. If you do, suppress all other moods that share a color with the discarded card. Those moods remain suppressed for as long as you have this mood." */
final class Chaos025Effect extends AbstractChaosMoodEffect
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
        $state->moveHandToDiscard($playerId, $discardCardId);

        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $state->colorOf($mood->cardId) === $color) {
                $state->suppress($mood->cardId, 'while_source_in_play', $cardId);
            }
        }
    }
}

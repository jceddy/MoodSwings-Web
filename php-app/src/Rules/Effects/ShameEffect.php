<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Shame: "After playing this mood, you may discard a card from your
 * hand. If you do, suppress all other moods that share a color with the
 * discarded card. Those moods remain suppressed for as long as you have
 * this mood."
 */
final class ShameEffect extends AbstractMoodEffect
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

        $color = $state->colorOf($discardCardId);
        $state->moveHandToDiscard($playerId, $discardCardId);

        $targets = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $state->colorOf($mood->cardId) === $color) {
                $targets[] = $mood->cardId;
            }
        }

        foreach ($targets as $targetCardId) {
            $state->suppress($targetCardId, 'while_source_in_play', $cardId);
        }
    }
}

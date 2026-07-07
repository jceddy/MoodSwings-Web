<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Benevolence: "After playing this mood, you may play an additional mood
 * this turn if it doesn't share a color with any of your moods." Like
 * Charity, the grant itself has no cost, so it's given automatically
 * whenever the condition holds -- declining to use it just means not
 * playing another card.
 */
final class BenevolenceEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $color = $state->colorOf($cardId);

        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            if ($mood->cardId !== $cardId && $state->colorOf($mood->cardId) === $color) {
                return;
            }
        }

        $state->grantExtraPlay();
    }
}

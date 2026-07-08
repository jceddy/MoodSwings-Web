<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Idealism: "After playing this mood, you may play an additional mood
 * this turn. While in play, for each of your moods with dice in its
 * lower left corner, use the dice total in its top right corner or
 * lower left corner, whichever is higher, to determine its value." The
 * grant is unconditional, like Charity's. The blanket dice-value rule
 * needs no method here at all -- BoardState::valueOf() applies it to
 * every mood Idealism's owner controls directly, the same way it
 * resolves Encouragement's single tagged mood.
 */
final class IdealismEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(1);
    }
}

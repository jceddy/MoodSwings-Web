<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Eagerness: "After playing this mood, you may play an additional mood
 * this turn if it shares a color with one of your moods." The mirror
 * image of Benevolence's restriction -- see BenevolenceEffect.
 */
final class EagernessEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(1, ['type' => 'shares_color_with_your_moods']);
    }
}

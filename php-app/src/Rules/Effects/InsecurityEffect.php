<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Insecurity: "After playing this mood, you may play an additional mood
 * this turn. If you do, after scoring, put that mood into your hand if
 * it's still in play." Same mechanism as Gluttony, just returning the
 * tagged mood to hand instead of discard -- see GluttonyEffect and
 * BoardState::useGrantFor()'s 'onUseEffectState' key.
 */
final class InsecurityEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(1, [
            'onUseEffectState' => [
                'afterScoring' => ['action' => 'return_to_hand', 'condition' => 'always'],
            ],
        ]);
    }
}

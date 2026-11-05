<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Frenzy: "After playing this mood -- After scoring this round, put this mood into the discard
 * pile." Tags itself with the generic 'afterScoring' payload (the same one Recklessness and
 * Bashfulness use), which GameService resolves once this round is scored, win or lose.
 */
final class FrenzyEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->setEffectState($cardId, 'afterScoring', ['action' => 'discard', 'condition' => 'always']);
    }
}

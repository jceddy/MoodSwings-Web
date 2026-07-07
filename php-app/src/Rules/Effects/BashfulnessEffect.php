<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Bashfulness: "After playing this mood, after scoring this round, if you
 * won the round, put this mood on the bottom of the deck and draw a
 * card." Tagged via the well-known 'afterScoring' effectState key, which
 * GameService::applyAfterScoringHooks() resolves once the round's winner
 * is known.
 */
final class BashfulnessEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->setEffectState($cardId, 'afterScoring', ['action' => 'bottom_and_draw', 'condition' => 'if_won']);
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_030 (common, after_playing): "After scoring this round, if you
 * won the round, put this mood on the bottom of the deck and draw a
 * card." Reuses the exact generic 'afterScoring' self-tag
 * GameService::applyAfterScoringHooks() already reads for every in-play
 * mood regardless of effect_key -- see Effects/RecklessnessEffect.php and
 * friends for the same 'bottom_and_draw'/'if_won' shape.
 */
final class Chaos030Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->setEffectState($cardId, 'afterScoring', ['action' => 'bottom_and_draw', 'condition' => 'if_won']);
    }
}

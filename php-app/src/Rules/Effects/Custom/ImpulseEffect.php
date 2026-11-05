<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Impulse: "After playing this mood -- You may play an additional mood this turn. If you do, after scoring, put
 * this mood into your hand if it's still in play." The extra play is granted outright (the "may" covers whether it
 * is ever used); using it tags THIS mood to return to the hand after scoring -- the grant's
 * 'onUseSourceEffectState', applied by MoodPlayService when the play is actually made -- so an Impulse whose
 * extra play is never spent stays in play. The tag is the same self 'afterScoring' return Insecurity uses.
 */
final class ImpulseEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(1, [
            'onUseSourceEffectState' => ['afterScoring' => ['action' => 'return_to_hand', 'condition' => 'always']],
        ], sourceCardId: $cardId);
    }
}

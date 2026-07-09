<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Gluttony: "After playing this mood, you may play an additional mood
 * this turn. If you do, after scoring, put that mood into the discard
 * pile if it's still in play." The grant itself is unconditional
 * (Charity-style); the "if you do" part is handled for free by tagging
 * the grant with an 'onUseEffectState' payload that only ever gets
 * applied to whichever specific card actually ends up consuming this
 * grant -- see BoardState::useGrantFor() and MoodPlayService. An unused
 * grant simply expires at end of turn with no mood ever tagged.
 */
final class GluttonyEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(1, [
            'onUseEffectState' => [
                'afterScoring' => ['action' => 'discard', 'condition' => 'always'],
            ],
        ], sourceCardId: $cardId);
    }
}

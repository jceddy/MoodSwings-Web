<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * chaos_097 (rare, while_in_play): "While scoring, you may score one of
 * your opponents' moods as though it were yours. (They also still score
 * it.)" Identical printed text to Passion's own "while in play" ability
 * (see RoundScorer's own docblock) -- simplified to always apply (rather
 * than pausing for a real accept/decline decision, see ChaosMoodEffect's
 * own class docblock) against whichever opponent mood is currently worth
 * the most, since there's no downside captured by this engine to
 * declining a pure bonus.
 */
final class Chaos097Effect extends AbstractChaosMoodEffect
{
    public function scoringBonus(BoardState $state, int $cardId, int $ownerId): int
    {
        $best = 0;
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->ownerId !== $ownerId && !$state->isTeammate($ownerId, $mood->ownerId)) {
                $best = max($best, $state->valueOf($mood->cardId));
            }
        }

        return $best;
    }
}

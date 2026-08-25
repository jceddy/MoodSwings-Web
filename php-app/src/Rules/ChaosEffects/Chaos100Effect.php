<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * chaos_100 (rare, while_in_play): "After scoring, if you have the
 * lowest score out of all players, you may put this mood into the
 * discard pile. If you do, choose any opponent's mood - that mood's
 * owner gives it to you." Fires reactively with no request-scoped
 * PlayerChoices to read from -- simplified to always apply when the
 * condition holds (see Chaos097Effect's own docblock), targeting a
 * uniformly-random opponent's mood.
 */
final class Chaos100Effect extends AbstractChaosMoodEffect
{
    public function afterScoring(BoardState $state, int $cardId, int $ownerId, array $scores, array $winningGamePlayerIds, int $lowestScorePlayerId): void
    {
        if ($ownerId !== $lowestScorePlayerId || !$state->isInPlay($cardId)) {
            return;
        }

        $opponentMoods = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $mood->ownerId !== $ownerId && !$state->isTeammate($ownerId, $mood->ownerId)) {
                $opponentMoods[$mood->cardId] = $mood;
            }
        }

        $state->moveInPlayToDiscard($cardId);

        if ($opponentMoods === []) {
            return;
        }
        $takenCardId = array_rand($opponentMoods);
        $state->giveInPlayToPlayer($takenCardId, $ownerId);
    }
}

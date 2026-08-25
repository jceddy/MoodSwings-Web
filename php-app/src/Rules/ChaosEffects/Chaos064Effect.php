<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * chaos_064 (rare, while_in_play): "Each time a mood is put into the
 * discard pile, you may choose an opponent's mood and permanently reduce
 * its value by 1. If this chosen mood's value would become less than 0,
 * put it in the discard pile instead." Fires reactively for every
 * discard, with no request-scoped PlayerChoices to read from -- the
 * target is simplified to a uniformly-random opponent's mood, matching
 * every other "you may choose ..." reactive chaos effect (see
 * ChaosMoodEffect's own class docblock).
 */
final class Chaos064Effect extends AbstractChaosMoodEffect
{
    public function onMoodDiscarded(BoardState $state, int $cardId, int $ownerId, int $discardedCardId, int $discardedOwnerId, int $discardedValue): void
    {
        $opponentMoods = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->ownerId !== $ownerId && !$state->isTeammate($ownerId, $mood->ownerId)) {
                $opponentMoods[$mood->cardId] = $mood;
            }
        }
        if ($opponentMoods === []) {
            return;
        }

        $targetCardId = array_rand($opponentMoods);
        $newValue = $state->valueOf($targetCardId) - 1;
        if ($newValue < 0) {
            $state->moveInPlayToDiscard($targetCardId);

            return;
        }

        $state->setValueOverride($targetCardId, $newValue);
    }
}

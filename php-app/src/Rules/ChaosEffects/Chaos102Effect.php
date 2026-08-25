<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/** chaos_102 (rare, while_in_play): "At the start of each of your turns, if another player has more moods than you, you may play an additional mood this turn. (Moods are cards in play.)" */
final class Chaos102Effect extends AbstractChaosMoodEffect
{
    public function perpetualTurnStartGrants(BoardState $state, int $cardId, int $ownerId): array
    {
        $ownCount = count($state->moodsOwnedBy($ownerId));
        foreach ($state->activePlayerOrder() as $otherPlayerId) {
            if ($otherPlayerId !== $ownerId && count($state->moodsOwnedBy($otherPlayerId)) > $ownCount) {
                return [[]];
            }
        }

        return [];
    }
}

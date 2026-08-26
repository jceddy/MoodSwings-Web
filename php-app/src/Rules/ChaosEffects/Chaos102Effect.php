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

    /**
     * A bug caught live: unlike chaos_121/124's own explicit "including
     * the turn you play this mood" wording, this effect's own printed
     * text scopes the grant to "the start of each of your turns" -- the
     * turn this mood itself gets played is already underway by the time
     * it enters play, so it was never "the start of" that turn to begin
     * with. See ChaosMoodEffect::perpetualGrantsIncludeTheTurnPlayed()'s
     * own docblock.
     */
    public function perpetualGrantsIncludeTheTurnPlayed(): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/** chaos_079 (mythic, while_in_play): "This mood's value increases by 1 for each of your moods (including itself). If there are no cards in your hand, this mood's value instead increases by 2 for each of your moods (including itself)." */
final class Chaos079Effect extends AbstractChaosMoodEffect
{
    public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
    {
        $ownerId = $state->ownerOf($cardId);
        $perMood = $state->hand($ownerId) === [] ? 2 : 1;

        return $incomingValue + $perMood * count($state->moodsOwnedBy($ownerId));
    }
}

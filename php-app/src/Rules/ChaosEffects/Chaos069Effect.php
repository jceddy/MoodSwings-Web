<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * chaos_069 (rare, while_in_play): "You may play moods from the discard
 * pile as though they were in your hand." Simplified to one extra
 * discard-sourced play granted every turn (the same shape Harmony's own
 * identical-wording grant already uses), rather than rearchitecting the
 * engine's own hand/discard play-source legality check -- a conservative
 * approximation of "any of your plays can also come from discard," not
 * literally boundless.
 */
final class Chaos069Effect extends AbstractChaosMoodEffect
{
    public function perpetualTurnStartGrants(BoardState $state, int $cardId, int $ownerId): array
    {
        return [['source' => 'discard']];
    }
}

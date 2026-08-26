<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/** chaos_116 (uncommon, while_in_play): "While scoring, you may score one of your moods an extra time." Identical printed text to Enthusiasm's own ability -- simplified to always apply, against whichever of the owner's own moods is currently worth the most (see Chaos097Effect's own docblock). */
final class Chaos116Effect extends AbstractChaosMoodEffect
{
    public function scoringBonus(BoardState $state, int $cardId, int $ownerId): int
    {
        $values = array_map(static fn ($mood) => $state->valueOf($mood->cardId), $state->moodsOwnedBy($ownerId));

        return $values === [] ? 0 : max($values);
    }
}

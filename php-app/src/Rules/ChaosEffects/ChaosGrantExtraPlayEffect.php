<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_002/003/013/017/065/114/123: "After playing this mood, you may
 * play [up to N] additional mood(s) this turn [restricted]." A thin
 * wrapper around BoardState::grantExtraPlay() -- the grant itself has no
 * cost, so it's simply given (declining to use it just means not playing
 * the bonus card), exactly like the base game's own Charity/Kindness/
 * Friendliness/Eagerness/Harmony/Grief/Benevolence do (see
 * Effects/KindnessEffect.php and friends for the identical restriction
 * shapes this reuses verbatim).
 *
 * @see BoardState::grantExtraPlay() for the restriction array shape.
 */
final class ChaosGrantExtraPlayEffect extends AbstractChaosMoodEffect
{
    /** @param ?array{type?: string, values?: int[], source?: string} $restriction */
    public function __construct(
        private readonly int $count = 1,
        private readonly ?array $restriction = null,
    ) {
    }

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay($this->count, $this->restriction, sourceCardId: $cardId);
    }
}

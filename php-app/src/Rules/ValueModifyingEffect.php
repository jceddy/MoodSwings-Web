<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * Implemented by moods whose "while in play" ability changes the value of OTHER moods (or of any mood,
 * itself included) -- Ardor, Humility, Kinship, Verve, Orderliness. BoardState::valueOf() adds
 * valueModifierFor() for every such mood in play on top of the target's own computed value.
 *
 * Must be a pure read that never calls valueOf() on the target or the source (colors, printed numbers
 * and effect state only), or the modifiers would recurse into each other.
 */
interface ValueModifyingEffect
{
    /** What the in-play mood $sourceCardId adds to the value of the in-play mood $targetCardId (0 when it doesn't apply). */
    public function valueModifierFor(BoardState $state, int $sourceCardId, int $targetCardId): int;
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ValueModifyingEffect;

/**
 * Verve: "While in play -- Each of your red moods that you played this round has its value increased by
 * 2." "Played this round" is the same tag Patience/Glee read (the round it entered play in, and the
 * player who played it): a red mood someone else played, or that you took, doesn't count.
 */
final class VerveEffect extends AbstractMoodEffect implements ValueModifyingEffect
{
    private const BONUS = 2;

    public function valueModifierFor(BoardState $state, int $sourceCardId, int $targetCardId): int
    {
        $ownerId = $state->ownerOf($sourceCardId);
        if ($state->ownerOf($targetCardId) !== $ownerId || $state->colorOf($targetCardId) !== 'red') {
            return 0;
        }

        $currentRound = $state->currentRoundNumber();
        $playedThisRound = $currentRound !== null
            && $state->effectState($targetCardId, 'playedInRound') === $currentRound
            && $state->effectState($targetCardId, 'playedByPlayerId') === $ownerId;

        return $playedThisRound ? self::BONUS : 0;
    }
}

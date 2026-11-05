<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Malevolence: "After playing this mood -- Choose odd or even. Choose up to two players. For each chosen player,
 * put one of their moods with a number of the chosen type in its top right corner into the discard pile. (0 is
 * even.)" Spite's removal with a chosen parity, judged on the printed number (not the current value). Modeled like
 * Spite: up to two moods, one per chosen player; the parity is required, the targets optional.
 */
final class MalevolenceEffect extends AbstractMoodEffect
{
    private const MAX_TARGETS = 2;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $parity = CustomEffectSupport::requireParity($choices->requireString('parity'));
        $targets = array_values(array_unique($choices->ints('target_mood_ids')));
        if (count($targets) > self::MAX_TARGETS) {
            throw new InvalidChoiceException('Malevolence can only affect up to two players');
        }

        $affectedOwners = [];
        foreach ($targets as $targetCardId) {
            if (!$state->isInPlay($targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not in play");
            }
            $owner = $state->ownerOf($targetCardId);
            if (isset($affectedOwners[$owner])) {
                throw new InvalidChoiceException('Malevolence can only affect one mood per chosen player');
            }
            if (!CustomEffectSupport::hasParity($state, $targetCardId, $parity)) {
                throw new InvalidChoiceException("Card {$targetCardId} does not have an {$parity} number in its top right corner");
            }
            $affectedOwners[$owner] = true;
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}

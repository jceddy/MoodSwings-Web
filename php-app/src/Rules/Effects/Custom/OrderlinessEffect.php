<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\ValueModifyingEffect;

/**
 * Orderliness: "After playing this mood -- Choose odd numbers or even numbers. While in play -- Each of
 * your other moods with a number of the chosen type in its top right corner has its value increased by
 * 1. (0 is even.)" The choice is remembered on the mood ('orderlinessParity').
 */
final class OrderlinessEffect extends AbstractMoodEffect implements ValueModifyingEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $parity = $choices->requireString('parity');
        if (!in_array($parity, ['odd', 'even'], true)) {
            throw new InvalidChoiceException("'{$parity}' is not odd or even");
        }

        $state->setEffectState($cardId, 'orderlinessParity', $parity);
    }

    public function valueModifierFor(BoardState $state, int $sourceCardId, int $targetCardId): int
    {
        $parity = $state->effectState($sourceCardId, 'orderlinessParity');
        if ($parity === null || $targetCardId === $sourceCardId || $state->ownerOf($targetCardId) !== $state->ownerOf($sourceCardId)) {
            return 0;
        }

        $isOdd = CustomEffectSupport::printedNumber($state, $targetCardId) % 2 === 1;

        return ($parity === 'odd') === $isOdd ? 1 : 0;
    }
}

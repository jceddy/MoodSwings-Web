<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Impartiality: "After playing this mood -- Choose odd or even. Suppress all other moods with a
 * number of the chosen type in their top right corners until the end of this round. (0 is
 * even.)" Mandatory; judged on printed numbers.
 */
final class ImpartialityEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $parity = $choices->requireString('parity');
        if ($parity !== 'odd' && $parity !== 'even') {
            throw new InvalidChoiceException("Impartiality's parity must be 'odd' or 'even'");
        }

        $targets = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId === $cardId) {
                continue;
            }
            $isEven = CustomEffectSupport::printedNumber($state, $mood->cardId) % 2 === 0;
            if ($isEven === ($parity === 'even')) {
                $targets[] = $mood->cardId;
            }
        }

        foreach ($targets as $targetCardId) {
            $state->suppress($targetCardId, 'end_of_round', $cardId);
        }
    }
}

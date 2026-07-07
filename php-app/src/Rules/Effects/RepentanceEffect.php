<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Repentance: "After playing this mood, you may choose a number. If you
 * do, suppress all other moods with the chosen value. They remain
 * suppressed until the end of this round." The first effect to use
 * 'end_of_round' suppression (see BoardState::clearEndOfRoundSuppressions())
 * rather than a source-tied one.
 */
final class RepentanceEffect extends AbstractMoodEffect
{
    private const MIN_VALUE = 0;
    private const MAX_VALUE = 12;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $value = $choices->int('value');
        if ($value === null) {
            return;
        }
        if ($value < self::MIN_VALUE || $value > self::MAX_VALUE) {
            throw new InvalidChoiceException('Repentance requires choosing a value between 0 and 12');
        }

        $qualifying = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $state->valueOf($mood->cardId) === $value) {
                $qualifying[] = $mood->cardId;
            }
        }

        foreach ($qualifying as $targetCardId) {
            $state->suppress($targetCardId, 'end_of_round');
        }
    }
}

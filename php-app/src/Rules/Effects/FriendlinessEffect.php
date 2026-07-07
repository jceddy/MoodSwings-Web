<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Friendliness: "After playing this mood, you may play an additional
 * mood this turn if it has a 0, 2, 4, or 6 in its top right corner." "It"
 * is whichever mood is chosen for the additional play -- see
 * BenevolenceEffect for why this is a restricted grant rather than a
 * one-time check of Friendliness's own printed value.
 */
final class FriendlinessEffect extends AbstractMoodEffect
{
    private const QUALIFYING_VALUES = [0, 2, 4, 6];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(1, ['type' => 'base_value_in', 'values' => self::QUALIFYING_VALUES]);
    }
}

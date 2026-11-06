<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Levelheadedness: "After playing this mood -- This round, each mood scores 2 instead of its value." Only scoring is
 * rewritten (RoundScorer, through BoardState::scoredValueOf(), and the live board totals): a mood's value for targeting,
 * Hellbent-style conditions and the bonuses that read a value (Enthusiasm, Passion) is its real one. A second
 * Levelheadedness changes nothing. The flag lives on the round, so the next round scores normally.
 */
final class LevelheadednessEffect extends AbstractMoodEffect
{
    private const SCORE = 2;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->setFlatMoodScore(self::SCORE);
    }
}

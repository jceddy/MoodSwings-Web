<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * Implemented by moods with an automatic "while scoring" ability that adds points to their owner's round
 * score (Fulfillment, Gratitude: "score each of your moods with a value of ... an extra time"). The extra
 * scoring is simply the value those moods are worth, so scoringBonus() returns the points to add on top of
 * the plain sum of values. RoundScorer::score() adds BoardState::scoringBonusOf() for every mood in play.
 * Exhilaration/Bliss/Enthusiasm/Passion predate this and are still handled inside RoundScorer itself.
 */
interface ScoringBonusEffect
{
    /** Points $cardId (owned by $ownerId) adds to its owner's score this round. */
    public function scoringBonus(BoardState $state, int $cardId, int $ownerId): int;
}

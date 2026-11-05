<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Urgency: "While in play -- This mood's value is 6 if you played two or more moods this round." Every mood its
 * owner has played this round counts -- Urgency's own play, plays from before it entered, and moods that have
 * since left play -- read from the round's own play record (BoardState::moodsPlayedThisRound(), kept on the live
 * round and emptied when it ends). "You" is whoever owns Urgency now. With one play a turn it stays at 3; it needs
 * an extra play (or an earlier play this round) to turn on, and it stays on through scoring.
 */
final class UrgencyEffect extends AbstractMoodEffect
{
    private const REQUIRED_PLAYS = 2;

    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));

        return $state->moodsPlayedThisRound($state->ownerOf($cardId)) >= self::REQUIRED_PLAYS ? $row['altValue'] : $row['baseValue'];
    }
}

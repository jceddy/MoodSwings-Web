<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\AfterScoringTriggerEffect;

/**
 * Perseverance: "While in play -- After scoring, if you have won fewer rounds than each other player, this
 * mood's value permanently increases by 2." Checked after every round that scores while it is in play,
 * counting the round that was just decided (its winner's win is recorded before after-scoring effects run),
 * so a player who has just fallen behind -- or just failed to catch up -- is judged on the new standings.
 * Uses the permanent per-mood value delta Maturity does (BoardState::adjustChaosValueDelta()).
 */
final class PerseveranceEffect extends AbstractMoodEffect implements AfterScoringTriggerEffect
{
    private const INCREASE = 2;

    public function afterScoring(BoardState $state, int $cardId, int $ownerId): void
    {
        if (CustomEffectSupport::hasWonFewerRoundsThanEachOther($state, $ownerId)) {
            $state->adjustChaosValueDelta($cardId, self::INCREASE);
        }
    }
}

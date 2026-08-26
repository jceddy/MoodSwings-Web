<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * chaos_021/chaos_092: "While in play, this mood's value is X if you
 * played it this round." Same 'playedInRound'/'playedByPlayerId'
 * effectState tags Effects/PlayedThisRoundValueEffect.php reads -- those
 * are stamped on the card itself by BoardState::moveHandToInPlay()/
 * moveDiscardToInPlay() regardless of whether the card carries an
 * attached chaos effect, so a chaos effect can read them exactly the same
 * way.
 */
final class ChaosPlayedThisRoundValueEffect extends AbstractChaosMoodEffect
{
    public function __construct(private readonly int $valueIfPlayedThisRound)
    {
    }

    public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
    {
        $currentRound = $state->currentRoundNumber();
        $playedInRound = $state->effectState($cardId, 'playedInRound');
        $playedByPlayerId = $state->effectState($cardId, 'playedByPlayerId');

        $qualifies = $currentRound !== null
            && $playedInRound === $currentRound
            && $playedByPlayerId === $state->ownerOf($cardId);

        return $qualifies ? $this->valueIfPlayedThisRound : $incomingValue;
    }
}

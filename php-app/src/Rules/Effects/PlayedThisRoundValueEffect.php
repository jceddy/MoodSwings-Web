<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Patience: "While in play, this mood's value is 1 if you played it this
 * round." Glee: "While in play, this mood's value is 6 if you played it
 * this round." Same shape either way -- the catalog's altValue is used
 * whenever the current round number matches the round the mood was
 * played in (the well-known 'playedInRound' effectState key, stamped by
 * BoardState::moveHandToInPlay()/moveDiscardToInPlay()), otherwise its
 * baseValue -- so one stateless class covers both cards, registered
 * twice with no constructor arguments needed.
 */
final class PlayedThisRoundValueEffect extends AbstractMoodEffect
{
    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));
        $currentRound = $state->currentRoundNumber();
        $playedInRound = $state->effectState($cardId, 'playedInRound');

        return $currentRound !== null && $playedInRound === $currentRound ? $row['altValue'] : $row['baseValue'];
    }
}

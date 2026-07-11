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
 * BoardState::moveHandToInPlay()/moveDiscardToInPlay()) AND the mood is
 * still owned by whoever played it ('playedByPlayerId', the same tag's
 * other half) -- "you" means whoever *currently* has it, so a mood that
 * changed hands since being played (Guile/Instability/Betrayal/
 * Recklessness/Arrogance/Avoidance/Chaos -- see BoardState::
 * giveInPlayToPlayer()) no longer qualifies for its new owner even in the
 * same round, exactly like Player A playing Glee for 6 and Player B then
 * taking it via Chaos, dropping it back to 0 since Player B didn't play
 * it. Otherwise the baseValue applies -- so one stateless class covers
 * both cards, registered twice with no constructor arguments needed.
 */
final class PlayedThisRoundValueEffect extends AbstractMoodEffect
{
    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));
        $currentRound = $state->currentRoundNumber();
        $playedInRound = $state->effectState($cardId, 'playedInRound');
        $playedByPlayerId = $state->effectState($cardId, 'playedByPlayerId');

        $qualifies = $currentRound !== null
            && $playedInRound === $currentRound
            && $playedByPlayerId === $state->ownerOf($cardId);

        return $qualifies ? $row['altValue'] : $row['baseValue'];
    }
}

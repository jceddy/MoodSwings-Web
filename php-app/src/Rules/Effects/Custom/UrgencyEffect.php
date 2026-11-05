<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Urgency: "While in play -- This mood's value is 6 if you played two or more moods this round." The card's own
 * rulings: the play that put Urgency into play counts, and plays from before it entered don't -- so it counts the
 * owner's plays since it arrived this round, itself included. A play by its owner is a reaction
 * (reactToAnotherPlay(), like Adrenaline's), tallied in the mood's own state under the round it belongs to, so a
 * new round starts the count over; Urgency's own entry counts when it was played this round by its owner (the
 * 'playedInRound'/'playedByPlayerId' tag every mood gets). With one play a turn it stays at 3; an extra play turns
 * it on, and it stays on through scoring.
 */
final class UrgencyEffect extends AbstractMoodEffect
{
    private const REQUIRED_PLAYS = 2;

    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));

        return $this->playsThisRound($state, $cardId) >= self::REQUIRED_PLAYS ? $row['altValue'] : $row['baseValue'];
    }

    public function reactToAnotherPlay(BoardState $state, int $reactorCardId, int $playedCardId, int $playerId, PlayerChoices $choices): void
    {
        $currentRound = $state->currentRoundNumber();
        if ($currentRound === null || $state->ownerOf($reactorCardId) !== $playerId) {
            return;
        }

        $tallied = $state->effectState($reactorCardId, 'urgencyRound') === $currentRound
            ? (int) $state->effectState($reactorCardId, 'urgencyPlays')
            : 0;
        $state->setEffectState($reactorCardId, 'urgencyRound', $currentRound);
        $state->setEffectState($reactorCardId, 'urgencyPlays', $tallied + 1);
    }

    private function playsThisRound(BoardState $state, int $cardId): int
    {
        $currentRound = $state->currentRoundNumber();
        if ($currentRound === null) {
            return 0;
        }

        $enteredThisRound = $state->effectState($cardId, 'playedInRound') === $currentRound
            && $state->effectState($cardId, 'playedByPlayerId') === $state->ownerOf($cardId);
        $tallied = $state->effectState($cardId, 'urgencyRound') === $currentRound
            ? (int) $state->effectState($cardId, 'urgencyPlays')
            : 0;

        return ($enteredThisRound ? 1 : 0) + $tallied;
    }
}

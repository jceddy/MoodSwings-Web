<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Reinvigoration: "After playing this mood -- Put all your other moods into the discard pile. Then you may play up to that many
 * additional moods this turn from the discard pile. You can't play any moods this turn except moods played this way." The whole
 * shared pile is open (what was just discarded and anything else in it), each replay is a real play, and the lock is a turn
 * restriction naming this card (BoardState::lockPlaysToGrantsFrom()): only plays granted by it are active, so another
 * Reinvigoration's lock and this one's cancel each other's plays and the board is just wiped, as the card's authors lean
 * toward. With no other moods the number is 0 and the card only costs you the turn.
 */
final class ReinvigorationEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $others = [];
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            if ($mood->cardId !== $cardId) {
                $others[] = $mood->cardId;
            }
        }

        foreach ($others as $otherCardId) {
            $state->moveInPlayToDiscard($otherCardId);
        }

        $state->grantExtraPlay(count($others), ['source' => 'discard'], sourceCardId: $cardId);
        $state->lockPlaysToGrantsFrom($cardId);
    }
}

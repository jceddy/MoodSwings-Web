<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Schadenfreude: "After playing this mood -- If another player discarded a card this turn, this mood's
 * value becomes 6." "Discarded" means from hand (moods put into the discard pile don't count), by any
 * player other than the one playing this -- a teammate included -- at any point since the turn began,
 * including at its start (Martyrdom) and by an earlier play this turn. The value is locked in once, like
 * Dignity's. The turn's record is BoardState::playersWhoDiscardedFromHandThisTurn().
 */
final class SchadenfreudeEffect extends AbstractMoodEffect
{
    private const LOCKED_VALUE = 6;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        foreach ($state->playersWhoDiscardedFromHandThisTurn() as $discarderId) {
            if ($discarderId !== $playerId) {
                $state->setValueOverride($cardId, self::LOCKED_VALUE);

                return;
            }
        }
    }
}

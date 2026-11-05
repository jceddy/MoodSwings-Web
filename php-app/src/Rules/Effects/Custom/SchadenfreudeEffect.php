<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Schadenfreude: "After playing this mood -- If another player discarded a card or had a mood put into the
 * discard pile this turn, this mood's value becomes 6." Counts a card another player discarded from hand and
 * one of their moods put into the discard pile from play, by any effect and at any point earlier this turn
 * (at its start by Martyrdom/Jealousy/Volatility, or by an earlier play) -- but not your own, and not a mood
 * that was returned to a hand or the deck instead. Any player other than the one playing this counts, a
 * teammate included. The value is locked in once, like Dignity's. The turn's record is
 * BoardState::playersWhoDiscardedThisTurn().
 */
final class SchadenfreudeEffect extends AbstractMoodEffect
{
    private const LOCKED_VALUE = 6;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        foreach ($state->playersWhoDiscardedThisTurn() as $discarderId) {
            if ($discarderId !== $playerId) {
                $state->setValueOverride($cardId, self::LOCKED_VALUE);

                return;
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Overconfidence: "After playing this mood -- You may play up to two additional moods this turn. If you do, you can't
 * play moods during your next turn." Two extra plays are granted outright; spending either one (the grant's
 * 'onUsePendingTurnRestriction', applied by MoodPlayService) puts a no-plays restriction on the owner's next turn
 * (BoardState::TURN_NO_PLAYS), once however many of them are used. Granted and never used, there is no cost.
 */
final class OverconfidenceEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(2, ['onUsePendingTurnRestriction' => BoardState::TURN_NO_PLAYS], $cardId);
    }
}

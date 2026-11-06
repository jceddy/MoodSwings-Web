<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Forgetfulness: "After playing this mood -- The winner of the previous round loses a round win. This round, no one draws
 * a card at the end of the round." The round win comes off the previous scored round's count when the board is next saved
 * (BoardState::forfeitPreviousRoundWin() / BoardStateRepository::save()) -- nothing in round 1 or after a round with no
 * winner (Awe) -- and may hit its own player; the draw is switched off for this round only
 * (BoardState::setNoEndOfRoundDraw(), kept on the live round). Hurt Feelings is untouched.
 */
final class ForgetfulnessEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->forfeitPreviousRoundWin();
        $state->setNoEndOfRoundDraw(true);
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/** Charity: "After playing this mood, you may play an additional mood this turn." No cost, so the extra play is simply granted -- declining to use it just means not playing another card. */
final class CharityEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(sourceCardId: $cardId);
    }
}

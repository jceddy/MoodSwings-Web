<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Joy: "After playing this mood, you may play an additional mood on your
 * next turn." Same mechanism as Generosity, just self-targeted --
 * see GenerosityEffect and GameService::computeFreshGrants(). The "may"
 * refers to whether the banked play actually gets used once granted, not
 * whether the grant is created, so it's tagged unconditionally.
 */
final class JoyEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->setEffectState($cardId, 'banksExtraPlayForPlayerId', $playerId);
    }
}

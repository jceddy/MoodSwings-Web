<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Harmony: "After playing this mood, you may play an additional mood
 * this turn from the discard pile." Like Charity, the grant itself has no
 * cost, so it's simply given -- declining to use it just means not
 * playing a card from the discard pile. The restriction on where the
 * bonus card must come from (rather than what it must be) is new here --
 * see BoardState::hasUsablePlayGrant()/MoodPlayService.
 */
final class HarmonyEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(1, ['source' => 'discard']);
    }
}

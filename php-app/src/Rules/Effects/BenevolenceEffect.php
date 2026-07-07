<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Benevolence: "After playing this mood, you may play an additional mood
 * this turn if it doesn't share a color with any of your moods." Unlike
 * Charity's unconditional grant, "it" refers to whichever mood is chosen
 * for the additional play, not Benevolence itself -- so the grant is
 * restricted, and BoardState checks the condition against the actual card
 * chosen once that bonus play is attempted (see
 * BoardState::hasUsablePlayGrant()/useGrantFor()).
 */
final class BenevolenceEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(1, ['type' => 'does_not_share_color_with_your_moods']);
    }
}

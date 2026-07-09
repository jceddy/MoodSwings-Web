<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Duplicity: "After playing this mood, you may play an additional mood
 * this turn. While in play, each time you play another mood, you may
 * have that mood's after-playing effect happen an additional time." The
 * first grant is unconditional, like Charity's. The second half needs
 * the effect registry to re-invoke another card's own afterPlaying(),
 * which no MoodEffect implementation has access to -- see
 * MoodPlayService's dedicated 'duplicity_repeat' handling.
 */
final class DuplicityEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(1, sourceCardId: $cardId);
    }
}

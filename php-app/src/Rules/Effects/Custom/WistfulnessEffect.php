<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Wistfulness: "After playing this mood -- You may play an additional mood this turn from the discard pile. While in play --
 * Each time you play another mood from the discard pile, you may have that mood's after-playing effect happen an additional
 * time." The extra play is Harmony's (granted outright, discard-pile only). The repeat is Duplicity's, for moods played from the
 * pile: MoodPlayService counts each Wistfulness in play among a pile-played mood's repeat sources (not for Wistfulness's own
 * play), so the pile play it grants already triggers twice.
 */
final class WistfulnessEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(1, ['source' => 'discard'], sourceCardId: $cardId);
    }
}

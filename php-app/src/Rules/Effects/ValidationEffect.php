<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Validation: "After playing this mood, you may play an additional mood
 * this turn. While in play, each time you play another mood with a 0 or
 * 1 in its top right corner, you may play an additional mood this turn."
 * The first grant is unconditional, like Charity's (the "may" covers
 * whether the granted play gets used, not whether it's created). The
 * second reacts to Validation's own owner playing a different,
 * low-valued mood -- see MoodEffect::reactToAnotherPlay() and
 * MoodPlayService.
 */
final class ValidationEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(1);
    }

    public function reactToAnotherPlay(BoardState $state, int $reactorCardId, int $playedCardId, int $playerId, PlayerChoices $choices): void
    {
        $baseValue = $state->catalogRow($state->effectiveCardId($playedCardId))['baseValue'];
        if (!in_array($baseValue, [0, 1], true)) {
            return;
        }

        if ($choices->bool('validation_extra_play')) {
            $state->grantExtraPlay(1);
        }
    }
}

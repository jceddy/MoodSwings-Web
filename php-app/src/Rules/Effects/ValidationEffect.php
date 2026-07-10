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
 *
 * "A 0 or 1 in its top right corner" is checked against $playedCardId's
 * own raw catalog row, not BoardState::effectiveCardId($playedCardId) --
 * per a rules judge ruling, Creativity's "top right corner" is its own
 * printed 0, regardless of what it's currently copying, since this reacts
 * to the physical card as it was in hand a moment ago, before "treating
 * it as an exact copy" ever entered the picture. So playing Creativity to
 * copy a mood with a base value above 1 still triggers this reaction,
 * exactly as playing an ordinary 0-value card would.
 */
final class ValidationEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(1, sourceCardId: $cardId);
    }

    public function reactToAnotherPlay(BoardState $state, int $reactorCardId, int $playedCardId, int $playerId, PlayerChoices $choices): void
    {
        $baseValue = $state->catalogRow($playedCardId)['baseValue'];
        if (!in_array($baseValue, [0, 1], true)) {
            return;
        }

        if ($choices->bool('validation_extra_play')) {
            $state->grantExtraPlay(1, sourceCardId: $reactorCardId);
        }
    }
}

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
 * Both grants are unconditional, like every other extra-play card
 * (Charity, Hope, Grace, etc.) -- the "may" covers whether a granted
 * play ever gets used (a player can always just decline it and pass/end
 * their turn instead), never whether the grant itself gets created. The
 * second reacts to Validation's own owner playing a different,
 * low-valued mood -- see MoodEffect::reactToAnotherPlay() and
 * MoodPlayService.
 *
 * "A 0 or 1 in its top right corner" is checked against
 * BoardState::effectiveCardId($playedCardId)'s catalog row, so a
 * Creativity copy is judged by whatever it's currently copying, not
 * Creativity's own printed 0 -- confirmed correct by a rules judge (an
 * earlier ruling to the contrary was later retracted as a mistake).
 */
final class ValidationEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(1, sourceCardId: $cardId);
    }

    public function reactToAnotherPlay(BoardState $state, int $reactorCardId, int $playedCardId, int $playerId, PlayerChoices $choices): void
    {
        $baseValue = $state->catalogRow($state->effectiveCardId($playedCardId))['baseValue'];
        if (!in_array($baseValue, [0, 1], true)) {
            return;
        }

        $state->grantExtraPlay(1, sourceCardId: $reactorCardId);
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Reflection: "While in play -- Each time you play another mood, you may discard a card from your hand. If you do,
 * draw a card." The optional discard rides with the triggering play ('reflection_discard_card_id'); a card that is
 * no longer in hand (another Reflection already discarded it) is skipped.
 */
final class ReflectionEffect extends AbstractMoodEffect
{
    private const KEY = 'reflection_discard_card_id';

    public function reactToAnotherPlay(BoardState $state, int $reactorCardId, int $playedCardId, int $playerId, PlayerChoices $choices): void
    {
        if (!$choices->has(self::KEY)) {
            return;
        }

        $discardCardId = $choices->requireInt(self::KEY);
        if (!$state->isInHand($playerId, $discardCardId)) {
            return;
        }

        $state->moveHandToDiscard($playerId, $discardCardId);
        $state->drawCard($playerId);
    }
}

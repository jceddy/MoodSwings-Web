<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_008/087/110/111: "After playing this mood, you may discard a card
 * from your hand with a <values> in its top right corner. If you do, this
 * mood's value becomes <boostedValue>." $qualifyingValues checks the
 * discarded hand card's own PRINTED base value (the number in its top
 * right corner), never its live/computed value, since a hand card has no
 * "in play" value to compute. Setting a value override on $cardId (rather
 * than returning a fixed value from computeValue()) is correct here since
 * this effect's shape is 'after_playing', not 'while_in_play' -- there's
 * no computeValue() hook for it to participate in at all, exactly like
 * every other card that permanently fixes its own value this way (see
 * BoardState::setValueOverride()).
 */
final class ChaosDiscardValueToBoostSelfEffect extends AbstractChaosMoodEffect
{
    /** @param int[] $qualifyingValues */
    public function __construct(
        private readonly array $qualifyingValues,
        private readonly int $boostedValue,
    ) {
    }

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardCardId = $choices->int('discard_card_id');
        if ($discardCardId === null) {
            return;
        }

        if (!$state->isInHand($playerId, $discardCardId)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not in your hand");
        }

        $baseValue = $state->catalogRow($state->catalogCardId($discardCardId))['baseValue'];
        if (!in_array($baseValue, $this->qualifyingValues, true)) {
            throw new InvalidChoiceException("Card {$discardCardId} does not have a qualifying value");
        }

        $state->moveHandToDiscard($playerId, $discardCardId);
        $state->setValueOverride($cardId, $this->boostedValue);
    }
}

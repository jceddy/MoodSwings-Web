<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Covers the "After playing this mood, you may discard a card from your
 * hand with [a qualifying value] in its top right corner. If you do,
 * this mood's value becomes [X]" pattern (Embarrassment, Cheer, Delight,
 * and others) with one parameterized implementation instead of near-
 * identical bespoke classes -- see PairedColorThresholdEffect for the
 * same idea applied to a different family. Dignity keeps its own bespoke
 * class since it was implemented before this pattern repeated enough to
 * justify consolidating.
 */
final class HandDiscardValueBoostEffect extends AbstractMoodEffect
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
            throw new InvalidChoiceException("Card {$discardCardId} is not in player {$playerId}'s hand");
        }

        // A card's printed ("top right corner") value is always its base
        // value -- a card in hand hasn't had a "while in play"/one-time
        // override applied to it yet.
        $baseValue = $state->catalogRow($discardCardId)['baseValue'];
        if (!in_array($baseValue, $this->qualifyingValues, true)) {
            throw new InvalidChoiceException('Discarded card does not show a qualifying value in its top right corner');
        }

        $state->moveHandToDiscard($playerId, $discardCardId);
        $state->setValueOverride($cardId, $this->boostedValue);
    }
}

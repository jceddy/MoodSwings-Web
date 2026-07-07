<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Dignity: "After playing this mood, you may discard a card from your
 * hand with a 0, 1, 2, or 3 in its top right corner. If you do, this
 * mood's value becomes 5." A one-time value bump, not a continuously
 * recomputed one -- once paid for, the value just stays 5 (see
 * BoardState::setValueOverride()), which is why Dignity's catalog row has
 * has_while_in_play_ability = 0 despite its value changing.
 */
final class DignityEffect extends AbstractMoodEffect
{
    private const QUALIFYING_VALUES = [0, 1, 2, 3];
    private const BOOSTED_VALUE = 5;

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
        if (!in_array($baseValue, self::QUALIFYING_VALUES, true)) {
            throw new InvalidChoiceException('Discarded card must show a 0, 1, 2, or 3 in its top right corner');
        }

        $state->moveHandToDiscard($playerId, $discardCardId);
        $state->setValueOverride($cardId, self::BOOSTED_VALUE);
    }
}

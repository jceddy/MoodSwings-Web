<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Cynicism: "After playing this mood, you may put a card from the
 * discard pile into an opponent's hand. If you do, this mood's value
 * becomes 6." Another one-time value override, this time costed from the
 * discard pile rather than the acting player's own hand or moods.
 */
final class CynicismEffect extends AbstractMoodEffect
{
    private const BOOSTED_VALUE = 6;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardCardId = $choices->int('discard_card_id');
        if ($discardCardId === null) {
            return;
        }
        if (!in_array($discardCardId, $state->discardPile(), true)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not in the discard pile");
        }

        $recipientId = $choices->requireInt('recipient_player_id');
        if ($recipientId === $playerId || !in_array($recipientId, $state->playerOrder(), true)) {
            throw new InvalidChoiceException("Player {$recipientId} is not a valid opponent");
        }

        $state->moveDiscardToHand($recipientId, $discardCardId);
        $state->setValueOverride($cardId, self::BOOSTED_VALUE);
    }
}

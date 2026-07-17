<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Condescension: "After playing this mood, you may give a card from
 * your hand to another player. If you do, this mood's value becomes 6."
 * The same one-time value override shape as Fascination, but the cost is
 * unconditional (any hand card, no color restriction).
 */
final class CondescensionEffect extends AbstractMoodEffect
{
    private const BOOSTED_VALUE = 6;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $giveCardId = $choices->int('give_card_id');
        if ($giveCardId === null) {
            return;
        }
        if (!$state->isInHand($playerId, $giveCardId)) {
            throw new InvalidChoiceException("Card {$giveCardId} is not in player {$playerId}'s hand");
        }

        $recipientId = $choices->requireInt('recipient_player_id');
        if ($recipientId === $playerId || !in_array($recipientId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$recipientId} is not a valid recipient");
        }

        $state->giveHandCardToPlayer($playerId, $recipientId, $giveCardId);
        $state->setValueOverride($cardId, self::BOOSTED_VALUE);
    }
}

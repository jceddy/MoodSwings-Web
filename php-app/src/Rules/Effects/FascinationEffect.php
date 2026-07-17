<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Fascination: "After playing this mood, you may reveal a blue or black
 * card from your hand and give it to another player. If you do, this
 * mood's value becomes 7." A one-time value override (like Dignity), paid
 * for by handing a card directly to another player rather than
 * discarding it (see BoardState::giveHandCardToPlayer()).
 */
final class FascinationEffect extends AbstractMoodEffect
{
    private const QUALIFYING_COLORS = ['blue', 'black'];
    private const BOOSTED_VALUE = 7;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $giveCardId = $choices->int('give_card_id');
        if ($giveCardId === null) {
            return;
        }
        if (!$state->isInHand($playerId, $giveCardId)) {
            throw new InvalidChoiceException("Card {$giveCardId} is not in player {$playerId}'s hand");
        }
        if (!in_array($state->colorOf($giveCardId), self::QUALIFYING_COLORS, true)) {
            throw new InvalidChoiceException('Revealed card must be blue or black');
        }

        $recipientId = $choices->requireInt('recipient_player_id');
        if ($recipientId === $playerId || !in_array($recipientId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$recipientId} is not a valid recipient");
        }

        $state->giveHandCardToPlayer($playerId, $recipientId, $giveCardId);
        $state->setValueOverride($cardId, self::BOOSTED_VALUE);
    }
}

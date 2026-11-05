<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Wariness: "After playing this mood -- If there are two or more blue and/or black moods, you may give a card from
 * your hand to another player. If you do, you may play an additional mood this turn." Moods of any owner count
 * (Wariness is green, so not itself). Both choices are optional together: naming a card needs a recipient, and
 * either one is refused while fewer than two blue/black moods are in play.
 */
final class WarinessEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $giveCardId = $choices->int('give_card_id');
        if ($giveCardId === null) {
            return;
        }

        if (CustomEffectSupport::countMoodsOfColors($state, ['blue', 'black']) < 2) {
            throw new InvalidChoiceException("There aren't two or more blue and/or black moods");
        }
        $recipientId = $choices->requireInt('recipient_player_id');
        if (!in_array($recipientId, CustomEffectSupport::otherPlayerIds($state, $playerId), true)) {
            throw new InvalidChoiceException('Wariness must give the card to another player');
        }
        if (!$state->isInHand($playerId, $giveCardId)) {
            throw new InvalidChoiceException("Card {$giveCardId} is not in player {$playerId}'s hand");
        }

        $state->giveHandCardToPlayer($playerId, $recipientId, $giveCardId);
        $state->grantExtraPlay(sourceCardId: $cardId);
    }
}

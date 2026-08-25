<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_058 (common, after_playing): "You may give a card from your hand to another player. If you do, this mood's value becomes 6." */
final class Chaos058Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $handCardId = $choices->int('hand_card_id');
        if ($handCardId === null) {
            return;
        }
        if (!$state->isInHand($playerId, $handCardId)) {
            throw new InvalidChoiceException("Card {$handCardId} is not in your hand");
        }
        $recipientId = $choices->requireInt('recipient_player_id');
        if (!in_array($recipientId, $state->activePlayerOrder(), true) || $recipientId === $playerId) {
            throw new InvalidChoiceException("Player {$recipientId} is not a valid recipient");
        }

        $state->giveHandCardToPlayer($playerId, $recipientId, $handCardId);
        $state->setValueOverride($cardId, 6);
    }
}

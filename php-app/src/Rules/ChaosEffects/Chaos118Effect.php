<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_118 (uncommon, after_playing): "You may reveal a blue or black card from your hand and give it to another player. If you do, this mood's value becomes 7." */
final class Chaos118Effect extends AbstractChaosMoodEffect
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
        if (!in_array($state->colorOf($handCardId), ['blue', 'black'], true)) {
            throw new InvalidChoiceException("Card {$handCardId} is not blue or black");
        }
        $recipientId = $choices->requireInt('recipient_player_id');
        if (!in_array($recipientId, $state->activePlayerOrder(), true) || $recipientId === $playerId) {
            throw new InvalidChoiceException("Player {$recipientId} is not a valid recipient");
        }

        $state->recordRevealedCard($handCardId);
        $state->giveHandCardToPlayer($playerId, $recipientId, $handCardId);
        $state->setValueOverride($cardId, 7);
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_062 (uncommon, after_playing): "You may put a card from the discard pile into an opponent's hand. If you do, this mood's value becomes 6." */
final class Chaos062Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardCardId = $choices->int('discard_card_id');
        if ($discardCardId === null) {
            return;
        }
        if (!$state->isInDiscardPile($discardCardId)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not in the discard pile");
        }
        $opponentId = $choices->requireInt('opponent_player_id');
        if (!in_array($opponentId, $state->activePlayerOrder(), true) || $opponentId === $playerId || $state->isTeammate($playerId, $opponentId)) {
            throw new InvalidChoiceException("Player {$opponentId} is not a valid opponent");
        }

        $state->moveDiscardToHand($opponentId, $discardCardId);
        $state->setChaosValueOverride($cardId, 6);
    }
}

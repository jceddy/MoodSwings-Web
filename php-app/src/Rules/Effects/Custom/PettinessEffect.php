<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Pettiness: "After playing this mood -- You may discard a card from your hand. If you do, choose a player. That
 * player can't play moods during their next turn." The discard is the "you may"; the player comes with it (any player,
 * you included). The ban waits for that player's next turn (BoardState::TURN_NO_PLAYS) and comes into force when its
 * grants are computed, so it hits this round's turn of a player who hasn't gone yet, or the next round's of one who has.
 */
final class PettinessEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $handCardId = $choices->int('hand_card_id');
        if ($handCardId === null) {
            return;
        }
        if (!$state->isInHand($playerId, $handCardId)) {
            throw new InvalidChoiceException("Card {$handCardId} is not in player {$playerId}'s hand");
        }
        $targetPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($targetPlayerId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
        }

        $state->moveHandToDiscard($playerId, $handCardId);
        $state->addPendingTurnRestriction($targetPlayerId, BoardState::TURN_NO_PLAYS);
    }
}

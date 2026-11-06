<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Grudge: "After playing this mood -- Choose an opponent. While in play -- During each of your turns (including the turn you play
 * this mood), you may play an additional mood for each round the chosen player has won. While in play -- If this mood would leave
 * play, put it on the bottom of the deck instead." The opponent is remembered on this mood ('grudgeTargetPlayerId'). This turn's
 * extra plays are granted here, like Hope's same-turn one (and lost if Grudge leaves play first); every later turn's are
 * GameService::computeFreshGrants() reading that opponent's round wins as the turn starts. The replacement effect is
 * BoardState's (moveInPlayToDiscard()/moveInPlayToHand()/moveInPlayToPlayersHand()): wherever Grudge would go, it goes to the bottom
 * of the deck, so nothing reaches the discard pile for it.
 */
final class GrudgeEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($targetPlayerId, CustomEffectSupport::opponentIds($state, $playerId), true)) {
            throw new InvalidChoiceException('Grudge must be held against one of your opponents');
        }

        $state->setEffectState($cardId, 'grudgeTargetPlayerId', $targetPlayerId);
        $state->grantExtraPlay($state->roundWinsOf($targetPlayerId), ['requiresSourceInPlay' => true], sourceCardId: $cardId);
    }
}

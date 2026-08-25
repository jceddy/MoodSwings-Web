<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_107 (rare, after_playing): "There is no scoring this round. No
 * one wins or loses this round. You choose which player goes first next
 * round. (This round, no one will draw a card or get Hurt Feelings, and
 * 'after scoring' effects won't happen.)" Identical printed text to
 * Effects/AweEffect.php -- reuses BoardState::markSkipScoringThisRound()
 * directly.
 */
final class Chaos107Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $chosenPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($chosenPlayerId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$chosenPlayerId} is not a valid player");
        }

        $state->markSkipScoringThisRound($cardId, $playerId, $chosenPlayerId);
    }
}

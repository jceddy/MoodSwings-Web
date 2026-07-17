<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Honor: "After playing this mood, choose a player. While in play, the
 * chosen player goes first each round regardless of who won the previous
 * round." The choice is stored under the well-known 'firstPlayerOverride'
 * effectState key -- see BoardState::firstPlayerOverride() -- which
 * GameService consults instead of the round winner when starting the next
 * round, for as long as Honor stays in play.
 */
final class HonorEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $chosenPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($chosenPlayerId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$chosenPlayerId} is not a valid player");
        }

        $state->setEffectState($cardId, 'firstPlayerOverride', $chosenPlayerId);
    }
}

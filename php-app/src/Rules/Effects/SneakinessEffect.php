<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Sneakiness: "After playing this mood, choose an opponent. This round,
 * after scoring, swap your score with that player before determining who
 * wins the round." Tagged via the well-known 'swapScoreWithPlayerId'
 * effectState key, which GameService::applyScoreSwaps() resolves right
 * after scores are computed and before the winner is determined.
 */
final class SneakinessEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $opponentId = $choices->requireInt('opponent_player_id');
        if (!in_array($opponentId, $state->playerOrder(), true)) {
            throw new InvalidChoiceException("Player {$opponentId} is not a valid player");
        }
        if ($opponentId === $playerId) {
            throw new InvalidChoiceException('Sneakiness must target an opponent');
        }

        $state->setEffectState($cardId, 'swapScoreWithPlayerId', $opponentId);
    }
}

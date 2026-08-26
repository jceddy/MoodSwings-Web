<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_051 (mythic, after_playing): "Choose an opponent. This round,
 * after scoring, swap your score with that player before determining who
 * wins the round." Reuses the exact 'swapScoreWithPlayerId' effectState
 * tag GameService::applyScoreSwaps() already reads generically for every
 * in-play mood, regardless of effect_key -- no chaos-specific scoring
 * hook needed at all.
 */
final class Chaos051Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $opponentId = $choices->requireInt('opponent_player_id');
        if (!in_array($opponentId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$opponentId} is not a valid player");
        }
        if ($opponentId === $playerId || $state->isTeammate($playerId, $opponentId)) {
            throw new InvalidChoiceException('Only an opponent can be chosen');
        }

        $state->setEffectState($cardId, 'swapScoreWithPlayerId', $opponentId);
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Rudeness: "After playing this mood -- Each other player can't play additional moods during their next turn, and
 * the after-playing effects of moods they play during that turn don't happen. (This doesn't affect Hurt Feelings.)"
 * Nothing is chosen: every other player gets a Rudeness restriction waiting for their next turn
 * (BoardState::TURN_RUDENESS), which comes into force when that turn's grants are computed -- extra plays are dead for
 * the turn and its moods' after-playing effects are skipped. Hurt Feelings' second base play is a base play, so it stands.
 */
final class RudenessEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        foreach (CustomEffectSupport::otherPlayerIds($state, $playerId) as $otherPlayerId) {
            $state->addPendingTurnRestriction($otherPlayerId, BoardState::TURN_RUDENESS);
        }
    }
}

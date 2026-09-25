<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosLoopShortcut;

/**
 * chaos_040 (mythic, while_in_play): "Each time an opponent plays a
 * mood, draw a card." Registered on ChaosLoopShortcut::REGISTERED_EFFECT_KEYS
 * (issue #192 follow-up): unlike every other registered effect, the
 * player who benefits here (this effect's own owner) isn't the one doing
 * the looping -- it's whoever is looping's OPPONENT. If the looping
 * player's opponent holds this, every cycle of their opponent's loop
 * still hands them a free, unbounded draw, and GameService::buildChaosLoopShortcut()
 * offers the resulting shortcut to THIS effect's own owner (found by
 * scanning for whichever in-play mood carries it), not to whoever's turn
 * it currently is.
 */
final class Chaos040Effect extends AbstractChaosMoodEffect
{
    public function onMoodPlayed(BoardState $state, int $cardId, int $ownerId, int $playedByPlayerId, int $playedCardId): void
    {
        if ($playedByPlayerId !== $ownerId && !$state->isTeammate($ownerId, $playedByPlayerId)) {
            $state->drawCard($ownerId);
            $state->registerChaosEffectFired('chaos_040');
        }
    }

    public function loopShortcut(BoardState $state, int $cardId, int $ownerId): ?ChaosLoopShortcut
    {
        return ChaosLoopShortcut::draw($ownerId, count($state->deck($ownerId)));
    }
}

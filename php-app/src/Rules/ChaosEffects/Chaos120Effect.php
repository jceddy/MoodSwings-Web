<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_120 (common, after_playing): "The next time an opponent plays a
 * mood, you may permanently increase the value of one of your moods by
 * 1." A one-shot trigger: afterPlaying() arms a marker on this card, then
 * the first qualifying onMoodPlayed() disarms it and applies the bonus --
 * to a uniformly-random one of the owner's own moods, since this fires
 * reactively with no request-scoped PlayerChoices to read from (see
 * ChaosMoodEffect's own class docblock).
 */
final class Chaos120Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->setEffectState($cardId, 'chaos120Armed', true);
    }

    public function onMoodPlayed(BoardState $state, int $cardId, int $ownerId, int $playedByPlayerId, int $playedCardId): void
    {
        if (!$state->effectState($cardId, 'chaos120Armed')) {
            return;
        }
        if ($playedByPlayerId === $ownerId || $state->isTeammate($ownerId, $playedByPlayerId)) {
            return;
        }

        $state->clearEffectState($cardId, 'chaos120Armed');

        $ownMoods = $state->moodsOwnedBy($ownerId);
        if ($ownMoods === []) {
            return;
        }
        $targetCardId = array_rand($ownMoods);
        $state->setValueOverride($targetCardId, $state->valueOf($targetCardId) + 1);
    }
}

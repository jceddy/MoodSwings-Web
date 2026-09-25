<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosLoopShortcut;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_120 (common, after_playing): "The next time an opponent plays a
 * mood, you may permanently increase the value of one of your moods by
 * 1." A one-shot trigger: afterPlaying() arms a marker on this card, then
 * the first qualifying onMoodPlayed() disarms it and applies the bonus --
 * to a uniformly-random one of the owner's own moods, since this fires
 * reactively with no request-scoped PlayerChoices to read from (see
 * ChaosMoodEffect's own class docblock). A DELTA ("increase ... BY 1"),
 * not an absolute override -- adjustChaosValueDelta() stacks this with
 * whatever the target's value already is instead of replacing it; see
 * that method's own docblock on BoardState.
 *
 * Registered on ChaosLoopShortcut::REGISTERED_EFFECT_KEYS (issue #192
 * follow-up) for completeness -- this is the effect that answers "is
 * there a potential issue with effects that give a mood +1 point
 * permanently?" -- but it can't actually reach the fire-count threshold
 * mid-loop today: the arm/disarm pair above only ever fires ONCE per
 * arming (afterPlaying() arms it, the very next qualifying opponent play
 * disarms it and consumes it), and re-arming requires THIS card itself
 * to be replayed via afterPlaying() -- since chaos_120 isn't one of the
 * four cards any of the three known loops actually cycles, it never gets
 * replayed by one, so it fires at most once per loop, never three times.
 * Registered anyway as insurance against a future card/combo that
 * removes the arm/disarm limitation, rather than treated as "safe, so
 * skip it."
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
        $state->adjustChaosValueDelta($targetCardId, 1);
        $state->registerChaosEffectFired('chaos_120');
    }

    public function loopShortcut(BoardState $state, int $cardId, int $ownerId): ?ChaosLoopShortcut
    {
        $ownMoods = $state->moodsOwnedBy($ownerId);
        if ($ownMoods === []) {
            return null;
        }

        return ChaosLoopShortcut::valueBoost(array_rand($ownMoods));
    }
}

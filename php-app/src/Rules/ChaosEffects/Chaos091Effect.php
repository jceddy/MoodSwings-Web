<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_091 (uncommon, after_playing): "Each player chooses one of their
 * highest value moods and puts it into the discard pile." Each player's
 * own choice among any of their own tied-for-highest moods is simplified
 * to a uniformly-random one of them (see ChaosMoodEffect's own docblock).
 * Every player's own discarded mood is picked from a snapshot of the
 * board taken before any discard happens, so one player's discard can
 * never change what counts as another player's own highest.
 */
final class Chaos091Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $toDiscard = [];
        foreach ($state->activePlayerOrder() as $ownerId) {
            $moods = $state->moodsOwnedBy($ownerId);
            if ($moods === []) {
                continue;
            }
            $highest = max(array_map(static fn ($mood) => $state->valueOf($mood->cardId), $moods));
            $candidates = array_filter($moods, static fn ($mood) => $state->valueOf($mood->cardId) === $highest);
            $toDiscard[] = array_rand($candidates);
        }

        foreach ($toDiscard as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}

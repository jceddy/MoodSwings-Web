<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_029 (rare, after_playing): "Choose left or right. Each player
 * chooses one of their moods and gives it to the next player in the
 * chosen direction." Each OTHER player's own "chosen mood" is simplified
 * to a uniformly-random one of their own moods (see ChaosMoodEffect's own
 * docblock on why chaos effects never pause for another player's
 * decision). Every player's own outgoing mood is picked in one pass
 * before any transfer happens, so an earlier transfer can never affect a
 * later player's own pool of moods to choose from.
 */
final class Chaos029Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $direction = $choices->requireString('direction');
        if (!in_array($direction, ['left', 'right'], true)) {
            throw new InvalidChoiceException("'{$direction}' is not a valid direction");
        }

        $outgoing = [];
        foreach ($state->activePlayerOrder() as $ownerId) {
            $moods = $state->moodsOwnedBy($ownerId);
            if ($moods === []) {
                continue;
            }
            $outgoing[$ownerId] = array_rand($moods);
        }

        foreach ($outgoing as $ownerId => $movingCardId) {
            $recipientId = $state->activeNeighbor($ownerId, $direction);
            if ($recipientId !== null && $recipientId !== $ownerId) {
                $state->giveInPlayToPlayer($movingCardId, $recipientId);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/** Largesse: "After playing this mood -- Each other player draws a card." In turn order from the next player. */
final class LargesseEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        foreach (CustomEffectSupport::otherPlayerIds($state, $playerId) as $otherPlayerId) {
            $state->drawCard($otherPlayerId);
        }
    }
}

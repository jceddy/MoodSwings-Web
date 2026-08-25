<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_005/044/055/083/126: "After playing this mood, put a <color> mood
 * with value 1 named <Token> into play." One parameterized wrapper around
 * BoardState::spawnMoodInPlay() -- see migration 0183's token catalog rows
 * (cards.is_token = 1) for the five token catalog ids this is registered
 * with.
 */
final class ChaosTokenSpawnEffect extends AbstractChaosMoodEffect
{
    public function __construct(
        private readonly int $tokenCatalogCardId,
        private readonly int $count = 1,
    ) {
    }

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        for ($i = 0; $i < $this->count; $i++) {
            $state->spawnMoodInPlay($this->tokenCatalogCardId, $playerId);
        }
    }
}

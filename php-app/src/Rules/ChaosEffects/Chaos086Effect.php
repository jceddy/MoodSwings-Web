<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_086 (common, after_playing): "Choose another player. That player chooses a card from their hand and gives it to you." The other player's own choice of which card is simplified to a uniformly-random one. */
final class Chaos086Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($targetPlayerId, $state->activePlayerOrder(), true) || $targetPlayerId === $playerId) {
            throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
        }

        $hand = $state->hand($targetPlayerId);
        if ($hand === []) {
            return;
        }

        $state->giveHandCardToPlayer($targetPlayerId, $playerId, $hand[array_rand($hand)]);
    }
}

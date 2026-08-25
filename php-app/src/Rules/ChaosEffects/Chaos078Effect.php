<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_078 (common, after_playing): "Choose any number of players. Each
 * chosen player discards a card from their hand." Each chosen player's
 * own "a card" is simplified to a uniformly-random one of their own hand
 * cards; a chosen player with an empty hand simply discards nothing.
 */
final class Chaos078Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $chosenPlayerIds = array_unique($choices->ints('target_player_ids'));

        foreach ($chosenPlayerIds as $targetPlayerId) {
            if (!in_array($targetPlayerId, $state->activePlayerOrder(), true)) {
                throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
            }
        }

        foreach ($chosenPlayerIds as $targetPlayerId) {
            $hand = $state->hand($targetPlayerId);
            if ($hand === []) {
                continue;
            }
            $state->moveHandToDiscard($targetPlayerId, $hand[array_rand($hand)]);
        }
    }
}
